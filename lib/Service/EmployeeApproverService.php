<?php

declare(strict_types=1);

namespace OCA\NextcloudVacation\Service;

use InvalidArgumentException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;

class EmployeeApproverService
{
    public function __construct(
        private IDBConnection $db,
        private IGroupManager $groupManager,
        private VacationReportService $reportService
    ) {
    }

    /** @return array<string, string> */
    public function candidates(): array
    {
        $users = [];
        foreach ($this->reportService->adminGroups() as $groupId) {
            $group = $this->groupManager->get($groupId);
            if ($group === null) {
                continue;
            }

            foreach ($group->getUsers() as $user) {
                $users[$user->getUID()] = $user->getDisplayName() ?: $user->getUID();
            }
        }

        asort($users, SORT_NATURAL | SORT_FLAG_CASE);
        return $users;
    }

    /** @return array<string, string> */
    public function assignments(): array
    {
        $candidates = $this->candidates();
        $qb = $this->db->getQueryBuilder();
        $qb->select('employee_id', 'approver_id')->from('vacation_approvers');
        $result = $qb->executeQuery();
        try {
            $assignments = [];
            while ($row = $result->fetch()) {
                $employeeId = (string)$row['employee_id'];
                $approverId = (string)$row['approver_id'];
                if (isset($candidates[$approverId])) {
                    $assignments[$employeeId] = $approverId;
                }
            }
            return $assignments;
        } finally {
            $result->closeCursor();
        }
    }

    public function assignedApproverId(string $employeeId): ?string
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('approver_id')
            ->from('vacation_approvers')
            ->where($qb->expr()->eq('employee_id', $qb->createNamedParameter($employeeId)))
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        try {
            $approverId = $result->fetchOne();
        } finally {
            $result->closeCursor();
        }

        if ($approverId === false || !$this->isValidApprover((string)$approverId)) {
            return null;
        }
        return (string)$approverId;
    }

    public function save(string $employeeId, string $approverId, string $actorId): void
    {
        $employeeId = trim($employeeId);
        $approverId = trim($approverId);
        if (!$this->reportService->isCalendarAdmin($actorId)) {
            throw new InvalidArgumentException('Only calendar admins may assign approvers.');
        }
        if ($employeeId === '' || !$this->reportService->isStaffUser($employeeId)) {
            throw new InvalidArgumentException('The employee is not in the configured staff group.');
        }
        if ($approverId !== '' && !$this->isValidApprover($approverId)) {
            throw new InvalidArgumentException('The selected approver is not in a configured admin group.');
        }

        $qb = $this->db->getQueryBuilder();
        if ($approverId === '') {
            $qb->delete('vacation_approvers')
                ->where($qb->expr()->eq('employee_id', $qb->createNamedParameter($employeeId)));
            $qb->executeStatement();
            return;
        }

        $existingId = $this->assignmentId($employeeId);
        $now = time();
        $qb = $this->db->getQueryBuilder();
        if ($existingId === null) {
            $qb->insert('vacation_approvers')->values([
                'employee_id' => $qb->createNamedParameter($employeeId),
                'approver_id' => $qb->createNamedParameter($approverId),
                'assigned_by' => $qb->createNamedParameter($actorId),
                'assigned_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            ]);
        } else {
            $qb->update('vacation_approvers')
                ->set('approver_id', $qb->createNamedParameter($approverId))
                ->set('assigned_by', $qb->createNamedParameter($actorId))
                ->set('assigned_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($existingId, IQueryBuilder::PARAM_INT)));
        }
        $qb->executeStatement();
    }

    private function isValidApprover(string $approverId): bool
    {
        return isset($this->candidates()[$approverId]) && $this->reportService->isCalendarAdmin($approverId);
    }

    private function assignmentId(string $employeeId): ?int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from('vacation_approvers')
            ->where($qb->expr()->eq('employee_id', $qb->createNamedParameter($employeeId)))
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        try {
            $id = $result->fetchOne();
            return $id === false ? null : (int)$id;
        } finally {
            $result->closeCursor();
        }
    }
}
