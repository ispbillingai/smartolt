<?php
declare(strict_types=1);

/** Encrypted configuration snapshots, kept in the site's database, outside downloadable files. */
final class ConfigBackups
{
    public function __construct(private OltManager $manager) {}

    public function capture(int $oltId): int
    {
        $config = $this->manager->readConfigBackup($oltId);
        $db = $this->manager->db();
        // The slow device read is complete. All local evidence of success must commit together.
        $db->beginTransaction();
        try {
            $db->prepare('INSERT INTO config_backups (olt_id, config_enc, sha256, bytes, created_by, created_at) VALUES (?,?,?,?,?,?)')
                ->execute([$oltId, $this->manager->encrypt($config), hash('sha256', $config), strlen($config), mb_substr($this->manager->user, 0, 60), OltManager::now()]);
            $id = (int)$db->lastInsertId();
            $db->prepare("UPDATE olts SET last_backup_at=?,backup_error='' WHERE id=?")->execute([OltManager::now(), $oltId]);
            $this->manager->log($oltId, 'olt.backup', 'Backup #' . $id, true, 'Configuration snapshot stored.');
            $db->commit();
            return $id;
        } catch (Throwable $failure) {
            if ($db->inTransaction()) $db->rollBack();
            throw $failure;
        }
    }

    public function schedule(int $oltId, int $hours): void
    {
        if (!$this->manager->olt($oltId)) throw new InvalidArgumentException('OLT not found.');
        if (!in_array($hours, [0, 6, 12, 24, 168], true)) throw new InvalidArgumentException('Choose a supported backup interval.');
        $this->manager->db()->prepare('UPDATE olts SET backup_interval_hours=? WHERE id=?')->execute([$hours, $oltId]);
        $this->manager->log($oltId, 'olt.backup_schedule', 'OLT ' . $oltId, true, $hours ? "Every {$hours} hours" : 'Disabled');
    }

    public function runDue(): array
    {
        $result = ['done' => [], 'failed' => []];
        foreach ($this->manager->olts() as $olt) {
            $hours = (int)$olt['backup_interval_hours'];
            if (!$hours || ($olt['last_backup_at'] && time() - AppTime::timestamp($olt['last_backup_at']) < $hours * 3600)) continue;
            try { $result['done'][$olt['id']] = $this->capture((int)$olt['id']); }
            catch (Throwable $e) {
                $result['failed'][$olt['id']] = $e->getMessage();
                $this->manager->db()->prepare('UPDATE olts SET backup_error=? WHERE id=?')->execute([mb_substr($e->getMessage(), 0, 500), $olt['id']]);
                $this->manager->log((int)$olt['id'], 'olt.backup', $olt['name'], false, $e->getMessage());
            }
        }
        return $result;
    }

    public function listing(int $oltId = 0): array
    {
        $sql = 'SELECT b.id, b.olt_id, b.sha256, b.bytes, b.created_by, b.created_at, o.name AS olt_name
            FROM config_backups b JOIN olts o ON o.id=b.olt_id';
        $st = $this->manager->db()->prepare($sql . ($oltId ? ' WHERE b.olt_id=?' : '') . ' ORDER BY b.id DESC LIMIT 500');
        $st->execute($oltId ? [$oltId] : []);
        return $st->fetchAll();
    }

    public function download(int $id): array
    {
        $st = $this->manager->db()->prepare('SELECT * FROM config_backups WHERE id=?');
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) throw new InvalidArgumentException('Backup not found.');
        $plain = $this->manager->decrypt($row['config_enc']);
        if (!hash_equals($row['sha256'], hash('sha256', $plain))) throw new RuntimeException('The stored backup failed its integrity check.');
        $this->manager->log((int)$row['olt_id'], 'olt.backup_download', 'Backup #' . $id, true, 'Downloaded configuration snapshot.');
        return ['filename' => 'olt-' . (int)$row['olt_id'] . '-backup-' . $id . '.cfg', 'content' => $plain];
    }

    public function delete(int $id): void
    {
        $st = $this->manager->db()->prepare('SELECT olt_id FROM config_backups WHERE id=?');
        $st->execute([$id]);
        $oltId = $st->fetchColumn();
        if ($oltId === false) throw new InvalidArgumentException('Backup not found.');
        $this->manager->db()->prepare('DELETE FROM config_backups WHERE id=?')->execute([$id]);
        $this->manager->log((int)$oltId, 'olt.backup_delete', 'Backup #' . $id, true, 'Deleted configuration snapshot.');
    }
}
