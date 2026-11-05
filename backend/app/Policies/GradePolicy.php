<?php

namespace App\Policies;

use App\Models\Grade;
use App\Models\Submission;
use App\Models\User;

class GradePolicy
{
    private function isAssignedStaff(
        User $user,
        Submission $submission,
        array $roles
    ): bool {
        $submission->loadMissing('assignment.course');

        $course = $submission->assignment->course;

        return $user->account_status === 'active'
            && $user->university_id !== null
            && $user->university_id === $course->university_id
            && in_array($user->role, $roles, true)
            && $user->staffAssignments()
                ->where('course_id', $course->id)
                ->exists();
    }

    public function view(User $user, Grade $grade): bool
    {
        $grade->loadMissing('submission.assignment.course');

        $submission = $grade->submission;

        if ($this->isAssignedStaff(
            $user,
            $submission,
            ['lecturer', 'teaching_assistant']
        )) {
            return true;
        }

        if (
            $user->role !== 'student' ||
            $user->account_status !== 'active' ||
            $grade->published_at === null ||
            $submission->student_id !== $user->id ||
            $user->university_id === null ||
            $user->university_id !==
                $submission->assignment->course->university_id
        ) {
            return false;
        }

        $latestSubmissionId = Submission::query()
            ->where('assignment_id', $submission->assignment_id)
            ->where('student_id', $user->id)
            ->orderByDesc('attempt_number')
            ->orderByDesc('id')
            ->value('id');

        return $latestSubmissionId === $submission->id
            && $user->enrollments()
                ->where(
                    'course_id',
                    $submission->assignment->course_id
                )
                ->where('status', 'active')
                ->exists();
    }

    public function create(
        User $user,
        Submission $submission
    ): bool {
        return $this->isAssignedStaff(
            $user,
            $submission,
            ['lecturer', 'teaching_assistant']
        ) && ! $submission->grade()->exists();
    }

    public function update(User $user, Grade $grade): bool
    {
        if ($grade->published_at !== null) {
            return $this->isAssignedStaff(
                $user,
                $grade->submission,
                ['lecturer']
            );
        }

        return $this->isAssignedStaff(
            $user,
            $grade->submission,
            ['lecturer', 'teaching_assistant']
        );
    }

    public function publish(User $user, Grade $grade): bool
    {
        return $grade->published_at === null
            && $this->isAssignedStaff(
                $user,
                $grade->submission,
                ['lecturer']
            );
    }
}
