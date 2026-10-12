<?php
/**
 * Keep central CDR tables usable at high volume (indexes + future partitions).
 *
 * Usage: php cron.php cdrscale
 *
 * =======================================
 * MagnusBilling open cluster
 * =======================================
 */
class CdrScaleCommand extends ConsoleCommand
{
    public function run($args)
    {
        $this->ensureIndex('pkg_cdr', 'idx_cdr_starttime_user', 'starttime, id_user');
        $this->ensureIndex('pkg_cdr', 'idx_cdr_id_server_start', 'id_server, starttime');
        $this->ensureIndex('pkg_cdr_failed', 'idx_cdrf_starttime', 'starttime');
        $this->ensureIndex('pkg_cdr_archive', 'idx_cdra_starttime', 'starttime');

        $this->ensureFuturePartitions('pkg_cdr');
        $this->ensureFuturePartitions('pkg_cdr_failed');

        echo "CDR scale maintenance completed.\n";
    }

    protected function ensureIndex($table, $index, $columns)
    {
        $sql = "SELECT COUNT(*) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i";
        $exists = (int) Yii::app()->db->createCommand($sql)->queryScalar([
            ':t' => $table,
            ':i' => $index,
        ]);
        if ($exists > 0) {
            echo "Index $table.$index already exists\n";
            return;
        }

        $tableExists = (int) Yii::app()->db->createCommand(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t"
        )->queryScalar([':t' => $table]);
        if ($tableExists < 1) {
            echo "Table $table not found, skip index $index\n";
            return;
        }

        try {
            Yii::app()->db->createCommand("ALTER TABLE `$table` ADD INDEX `$index` ($columns)")->execute();
            echo "Created index $table.$index\n";
        } catch (Exception $e) {
            echo "Failed index $table.$index: " . $e->getMessage() . "\n";
        }
    }

    /**
     * If the table is already RANGE-partitioned, add the next 3 monthly partitions.
     */
    protected function ensureFuturePartitions($table)
    {
        $sql = "SELECT PARTITION_NAME, PARTITION_DESCRIPTION
                FROM information_schema.PARTITIONS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t
                  AND PARTITION_NAME IS NOT NULL";
        $parts = Yii::app()->db->createCommand($sql)->queryAll(true, [':t' => $table]);
        if (! count($parts)) {
            return;
        }

        $hasMax = false;
        foreach ($parts as $part) {
            if (strtoupper((string) $part['PARTITION_DESCRIPTION']) === 'MAXVALUE') {
                $hasMax = true;
                break;
            }
        }
        if (! $hasMax) {
            echo "Table $table is partitioned without MAXVALUE; skip auto-extend\n";
            return;
        }

        for ($i = 1; $i <= 3; $i++) {
            $monthStart = new DateTime('first day of +' . $i . ' month');
            $monthStart->setTime(0, 0, 0);
            $nextMonth = clone $monthStart;
            $nextMonth->modify('+1 month');
            $name = 'p' . $monthStart->format('Ym');

            $exists = false;
            foreach ($parts as $part) {
                if ($part['PARTITION_NAME'] === $name) {
                    $exists = true;
                    break;
                }
            }
            if ($exists) {
                continue;
            }

            $lessThan = $nextMonth->getTimestamp();
            $reorg = "ALTER TABLE `$table` REORGANIZE PARTITION pmax INTO (
                PARTITION `$name` VALUES LESS THAN ($lessThan),
                PARTITION pmax VALUES LESS THAN MAXVALUE
            )";
            try {
                Yii::app()->db->createCommand($reorg)->execute();
                echo "Added partition $table.$name\n";
            } catch (Exception $e) {
                echo "Partition extend $table.$name skipped: " . $e->getMessage() . "\n";
                break;
            }
        }
    }
}
