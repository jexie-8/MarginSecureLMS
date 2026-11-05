<?php

namespace App\Policies;

use App\Models\Assignment;
use App\Models\Submission;
use App\Models\User;

class SubmissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->account_status === 'active';
    }

    /**
     * Determine whether a user can view a submission.
     */
    public function view(User $user, Submission $submission): bool
    {
        $submission->loadMissing('assignment.course');

        $course = $submission->assignment->course;

        if (
            $user->account_status !== 'active' ||
            $user->university_id === null ||
            $user->university_id !== $course->university_id
        ) {
            return false;
        }

        // Students can only view their own submissions.
        if ($user->role === 'student') {
            return $submission->student_id === $user->id
                && $user->enrollments()
                    ->where('course_id', $course->id)
                    ->where('status', 'active')
                    ->exists();
        }

        // Assigned lecturers and teaching assistants can
        // view submissions belonging to their courses.
        if (in_array($user->role, [
            'lecturer',
            'teaching_assistant',
        ], true)) {
            return $user->staffAssignments()
                ->where('course_id', $course->id)
                ->exists();
        }

        // Administrators do not automatically receive
        // access to private academic records.
        return false;
    }

    public function create(User $user, Assignment $assignment): bool
    {
        $assignment->loadMissing('course');

        return $user->role === 'student'
            && $user->account_status === 'active'
            && $assignment->published_at !== null
            && ($assignment->due_at === null || now()->lessThanOrEqualTo($assignment->due_at))
            && $user->university_id === $assignment->course->university_id
            && $user->enrollments()
                ->where('course_id', $assignment->course_id)
                ->where('status', 'active')
                ->exists();
    }
}
