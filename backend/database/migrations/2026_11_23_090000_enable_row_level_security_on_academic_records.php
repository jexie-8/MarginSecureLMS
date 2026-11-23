<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL Row-Level Security for the most sensitive academic records.
 *
 * Laravel policies remain the primary authorization layer. These database
 * policies are a second layer: even if an endpoint forgets an authorization
 * check, the database itself will not return or modify rows the signed-in
 * user is not entitled to.
 *
 * The application tells PostgreSQL who is calling by setting the session
 * variables app.user_id, app.user_role and app.university_id on every
 * authenticated request (see App\Http\Middleware\SetDatabaseUserContext).
 * When no user context is set, the policies match nothing.
 *
 * RLS never applies to PostgreSQL superusers. The runtime database account
 * used in production must therefore NOT be a superuser (see the margin_app
 * role created below, and copilot/README.md).
 */
return new class extends Migration
{
    private const TABLES = ['submissions', 'grades', 'grade_revisions'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->createContextFunctions();
        $this->createApplicationRole();

        foreach (self::TABLES as $table) {
            DB::unprepared("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::unprepared("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        }

        $this->createSubmissionPolicies();
        $this->createGradePolicies();
        $this->createGradeRevisionPolicies();
        $this->grantPrivileges();
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $policies = [
            'submissions' => ['submissions_select', 'submissions_insert'],
            'grades' => ['grades_select', 'grades_insert', 'grades_update'],
            'grade_revisions' => ['grade_revisions_select', 'grade_revisions_insert'],
        ];

        foreach ($policies as $table => $names) {
            foreach ($names as $name) {
                DB::unprepared("DROP POLICY IF EXISTS {$name} ON {$table}");
            }
        }

        foreach (self::TABLES as $table) {
            DB::unprepared("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
            DB::unprepared("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }

        foreach ([
            'app_is_latest_submission(bigint)',
            'app_is_enrolled(bigint)',
            'app_is_course_staff(bigint, text[])',
            'app_grade_course(bigint)',
            'app_submission_course(bigint)',
            'app_assignment_course(bigint)',
            'app_university_id()',
            'app_user_role()',
            'app_user_id()',
        ] as $function) {
            DB::unprepared("DROP FUNCTION IF EXISTS {$function}");
        }
    }

    private function createContextFunctions(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION app_user_id() RETURNS bigint
LANGUAGE sql STABLE AS
$$ SELECT NULLIF(current_setting('app.user_id', true), '')::bigint $$;

CREATE OR REPLACE FUNCTION app_user_role() RETURNS text
LANGUAGE sql STABLE AS
$$ SELECT NULLIF(current_setting('app.user_role', true), '') $$;

CREATE OR REPLACE FUNCTION app_university_id() RETURNS bigint
LANGUAGE sql STABLE AS
$$ SELECT NULLIF(current_setting('app.university_id', true), '')::bigint $$;

CREATE OR REPLACE FUNCTION app_assignment_course(p_assignment bigint) RETURNS bigint
LANGUAGE sql STABLE AS
$$ SELECT course_id FROM assignments WHERE id = p_assignment $$;

CREATE OR REPLACE FUNCTION app_submission_course(p_submission bigint) RETURNS bigint
LANGUAGE sql STABLE AS
$$ SELECT a.course_id
   FROM submissions s
   JOIN assignments a ON a.id = s.assignment_id
   WHERE s.id = p_submission $$;

CREATE OR REPLACE FUNCTION app_grade_course(p_grade bigint) RETURNS bigint
LANGUAGE sql STABLE AS
$$ SELECT a.course_id
   FROM grades g
   JOIN submissions s ON s.id = g.submission_id
   JOIN assignments a ON a.id = s.assignment_id
   WHERE g.id = p_grade $$;

-- True when the current user holds one of p_roles and is assigned to the
-- course inside their own university.
CREATE OR REPLACE FUNCTION app_is_course_staff(p_course bigint, p_roles text[]) RETURNS boolean
LANGUAGE sql STABLE AS
$$ SELECT COALESCE(
     app_user_id() IS NOT NULL
     AND app_user_role() = ANY (p_roles)
     AND EXISTS (
       SELECT 1
       FROM course_staff cs
       JOIN courses c ON c.id = cs.course_id
       WHERE cs.course_id = p_course
         AND cs.user_id = app_user_id()
         AND c.university_id = app_university_id()
     ), false) $$;

-- True when the current user is a student actively enrolled in the course
-- inside their own university.
CREATE OR REPLACE FUNCTION app_is_enrolled(p_course bigint) RETURNS boolean
LANGUAGE sql STABLE AS
$$ SELECT COALESCE(
     app_user_id() IS NOT NULL
     AND app_user_role() = 'student'
     AND EXISTS (
       SELECT 1
       FROM enrollments e
       JOIN courses c ON c.id = e.course_id
       WHERE e.course_id = p_course
         AND e.student_id = app_user_id()
         AND e.status = 'active'
         AND c.university_id = app_university_id()
     ), false) $$;

-- Students only see the grade of their most recent attempt.
CREATE OR REPLACE FUNCTION app_is_latest_submission(p_submission bigint) RETURNS boolean
LANGUAGE sql STABLE AS
$$ SELECT COALESCE(p_submission = (
     SELECT s2.id
     FROM submissions s1
     JOIN submissions s2
       ON s2.assignment_id = s1.assignment_id AND s2.student_id = s1.student_id
     WHERE s1.id = p_submission
     ORDER BY s2.attempt_number DESC, s2.id DESC
     LIMIT 1), false) $$;
SQL);
    }

    /**
     * A NOLOGIN role that holds exactly the privileges the application needs.
     * Production login accounts should be granted membership of it; tests use
     * it to prove the policies work for a non-superuser.
     */
    private function createApplicationRole(): void
    {
        DB::unprepared(<<<'SQL'
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'margin_app') THEN
    CREATE ROLE margin_app NOLOGIN NOSUPERUSER NOBYPASSRLS;
  END IF;
EXCEPTION WHEN insufficient_privilege THEN
  RAISE WARNING 'Could not create role margin_app; create it manually (see copilot/README.md).';
END
$$;
SQL);
    }

    private function createSubmissionPolicies(): void
    {
        DB::unprepared(<<<'SQL'
DROP POLICY IF EXISTS submissions_select ON submissions;
DROP POLICY IF EXISTS submissions_insert ON submissions;

-- Students see their own submissions while enrolled; assigned lecturers and
-- teaching assistants see submissions for their courses. Administrators see
-- none, matching the role-permission matrix.
CREATE POLICY submissions_select ON submissions FOR SELECT USING (
  (student_id = app_user_id() AND app_is_enrolled(app_assignment_course(assignment_id)))
  OR app_is_course_staff(app_assignment_course(assignment_id), ARRAY['lecturer', 'teaching_assistant'])
);

-- Only an enrolled student may create a submission, and only as themselves.
-- There is no UPDATE or DELETE policy: submissions are immutable.
CREATE POLICY submissions_insert ON submissions FOR INSERT WITH CHECK (
  student_id = app_user_id() AND app_is_enrolled(app_assignment_course(assignment_id))
);
SQL);
    }

    private function createGradePolicies(): void
    {
        DB::unprepared(<<<'SQL'
DROP POLICY IF EXISTS grades_select ON grades;
DROP POLICY IF EXISTS grades_insert ON grades;
DROP POLICY IF EXISTS grades_update ON grades;

-- Assigned staff see every grade in their course. A student sees only their
-- own grade, only once it is published, and only for their latest attempt.
CREATE POLICY grades_select ON grades FOR SELECT USING (
  app_is_course_staff(app_submission_course(submission_id), ARRAY['lecturer', 'teaching_assistant'])
  OR (
    published_at IS NOT NULL
    AND EXISTS (
      SELECT 1 FROM submissions s
      WHERE s.id = grades.submission_id AND s.student_id = app_user_id()
    )
    AND app_is_latest_submission(submission_id)
  )
);

-- Lecturers and TAs may create (unpublished) grades for their courses.
CREATE POLICY grades_insert ON grades FOR INSERT WITH CHECK (
  app_is_course_staff(app_submission_course(submission_id), ARRAY['lecturer', 'teaching_assistant'])
  AND published_at IS NULL
);

-- Lecturers and TAs may edit unpublished grades; only lecturers may publish
-- or edit a published grade. There is no DELETE policy.
CREATE POLICY grades_update ON grades FOR UPDATE USING (
  app_is_course_staff(app_submission_course(submission_id), ARRAY['lecturer', 'teaching_assistant'])
  AND (published_at IS NULL OR app_user_role() = 'lecturer')
) WITH CHECK (
  app_is_course_staff(app_submission_course(submission_id), ARRAY['lecturer', 'teaching_assistant'])
  AND (published_at IS NULL OR app_user_role() = 'lecturer')
);
SQL);
    }

    private function createGradeRevisionPolicies(): void
    {
        DB::unprepared(<<<'SQL'
DROP POLICY IF EXISTS grade_revisions_select ON grade_revisions;
DROP POLICY IF EXISTS grade_revisions_insert ON grade_revisions;

-- The history of changes to published grades is visible to assigned staff only.
CREATE POLICY grade_revisions_select ON grade_revisions FOR SELECT USING (
  app_is_course_staff(app_grade_course(grade_id), ARRAY['lecturer', 'teaching_assistant'])
);

-- Only an assigned lecturer can change a published grade, so only they can
-- record a revision, and always under their own identity.
CREATE POLICY grade_revisions_insert ON grade_revisions FOR INSERT WITH CHECK (
  app_is_course_staff(app_grade_course(grade_id), ARRAY['lecturer'])
  AND changed_by = app_user_id()
);
SQL);
    }

    private function grantPrivileges(): void
    {
        DB::unprepared(<<<'SQL'
DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'margin_app') THEN
    GRANT USAGE ON SCHEMA public TO margin_app;
    GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO margin_app;
    GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO margin_app;
    -- Academic records are append-only or restricted at the privilege level too.
    REVOKE UPDATE, DELETE ON submissions FROM margin_app;
    REVOKE DELETE ON grades FROM margin_app;
    REVOKE UPDATE, DELETE ON grade_revisions FROM margin_app;
  END IF;
END
$$;
SQL);
    }
};
