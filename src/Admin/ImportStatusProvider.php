<?php
namespace Lp\MatterhornImport\Admin;

final class ImportStatusProvider
{
    private const PHASES = [
        'read' => 'READ',
        'import' => 'IMPORT',
        'update' => 'UPDATE',
        'remove' => 'REMOVE',
    ];

    /** @param array<string,mixed> $run @return array<string,mixed> */
    public function present(array $run): array
    {
        $status = (string) ($run['status'] ?? '');
        $completed = 0;
        $phase = 'read';
        $phaseStatus = (string) ($run['read_status'] ?? 'pending');

        foreach (self::PHASES as $candidate => $label) {
            $candidateStatus = (string) ($run[$candidate . '_status'] ?? 'pending');
            if ($candidateStatus === 'completed') {
                ++$completed;
                continue;
            }
            $phase = $candidate;
            $phaseStatus = $candidateStatus;
            break;
        }

        if ($status === 'completed') {
            $completed = count(self::PHASES);
            $phase = 'remove';
            $phaseStatus = 'completed';
        }

        $phaseKeys = array_keys(self::PHASES);
        $phaseIndex = array_search($phase, $phaseKeys, true);
        $phaseIndex = $phaseIndex === false ? 1 : $phaseIndex + 1;
        $active = in_array($status, ['running', 'paused'], true);
        $resumable = in_array($status, ['running', 'paused'], true);

        return [
            'id_run' => (int) ($run['id_run'] ?? 0),
            'id_shop' => (int) ($run['id_shop'] ?? 0),
            'source' => (string) ($run['source'] ?? ''),
            'status' => $status,
            'read_status' => (string) ($run['read_status'] ?? 'pending'),
            'import_status' => (string) ($run['import_status'] ?? 'pending'),
            'update_status' => (string) ($run['update_status'] ?? 'pending'),
            'remove_status' => (string) ($run['remove_status'] ?? 'pending'),
            'source_total' => (int) ($run['source_total'] ?? 0),
            'source_valid' => (int) ($run['source_valid'] ?? 0),
            'source_invalid' => (int) ($run['source_invalid'] ?? 0),
            'source_duplicate' => (int) ($run['source_duplicate'] ?? 0),
            'read_checkpoint' => (int) ($run['read_checkpoint'] ?? 0),
            'import_done' => (int) ($run['import_done'] ?? 0),
            'import_failed' => (int) ($run['import_failed'] ?? 0),
            'update_done' => (int) ($run['update_done'] ?? 0),
            'update_skipped' => (int) ($run['update_skipped'] ?? 0),
            'update_failed' => (int) ($run['update_failed'] ?? 0),
            'remove_done' => (int) ($run['remove_done'] ?? 0),
            'remove_failed' => (int) ($run['remove_failed'] ?? 0),
            'started_at' => (string) ($run['started_at'] ?? ''),
            'finished_at' => $run['finished_at'] ?? null,
            'active' => $active,
            'resumable' => $resumable,
            'progress' => [
                'phase' => $phase,
                'label' => self::PHASES[$phase] ?? strtoupper($phase),
                'phase_status' => $phaseStatus,
                'phase_index' => $phaseIndex,
                'phase_count' => count(self::PHASES),
                'overall_percent' => $status === 'completed'
                    ? 100
                    : (int) floor(($completed / count(self::PHASES)) * 100),
                'indeterminate' => $active && $phaseStatus !== 'completed',
                'stats' => $this->stats($run),
            ],
        ];
    }

    /** @param array<string,mixed> $run */
    private function stats(array $run): string
    {
        return sprintf(
            'READ %d total / %d valid / %d invalid / %d duplicate; IMPORT %d done / %d failed; UPDATE %d done / %d skipped / %d failed; REMOVE %d done / %d failed.',
            (int) ($run['source_total'] ?? 0),
            (int) ($run['source_valid'] ?? 0),
            (int) ($run['source_invalid'] ?? 0),
            (int) ($run['source_duplicate'] ?? 0),
            (int) ($run['import_done'] ?? 0),
            (int) ($run['import_failed'] ?? 0),
            (int) ($run['update_done'] ?? 0),
            (int) ($run['update_skipped'] ?? 0),
            (int) ($run['update_failed'] ?? 0),
            (int) ($run['remove_done'] ?? 0),
            (int) ($run['remove_failed'] ?? 0)
        );
    }
}
