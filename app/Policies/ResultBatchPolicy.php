<?php

namespace App\Policies;

use App\Models\ResultBatch;
use App\Models\User;

class ResultBatchPolicy
{
    public function viewAny(User $u): bool
    {
        return $u->can('result.view');
    }

    public function create(User $u): bool
    {
        return $this->viewAny($u) && $u->can('result.generate');
    }

    public function view(User $u, ResultBatch $b): bool
    {
        return (int) $u->school_id === (int) $b->school_id && $this->viewAny($u);
    }

    public function transition(User $u, ResultBatch $b, string $action): bool
    {
        return $this->view($u, $b) && in_array($action, ['approve', 'publish', 'return'], true) && $u->can($action === 'return' ? 'result.approve' : 'result.'.$action);
    }

    public function approve(User $u, ResultBatch $b): bool
    {
        return $this->transition($u, $b, 'approve') && $this->available($b, 'GENERATED', 'VERIFICATION');
    }

    public function publish(User $u, ResultBatch $b): bool
    {
        return $this->transition($u, $b, 'publish') && $this->available($b, 'APPROVED', 'APPROVED');
    }

    public function returnForCorrection(User $u, ResultBatch $b): bool
    {
        return $this->transition($u, $b, 'return') && (($this->available($b, 'GENERATED', 'VERIFICATION')) || $this->available($b, 'APPROVED', 'APPROVED'));
    }

    private function available(ResultBatch $b, string $status, string $exam): bool
    {
        return $b->status === $status && $b->exam->status->value === $exam && $b->exam->academicYear->status->value === 'ACTIVE';
    }
}
