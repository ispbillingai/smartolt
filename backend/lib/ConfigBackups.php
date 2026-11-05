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
        $db->prepare('INSERT INTO config_backups (olt_id, config_enc, sha256, bytes, created_by, created_at) VALUES (?,?,?,?,?,?)')
            ->execute([$oltId, $this->manager->encrypt($config), hash('sha256', $config), strlen($config), mb_substr($this->manager->user, 0, 60), OltManager::now()]);
        $id = (int)$db->lastInsertId();
        $this->manager->log($oltId, 'olt.backup', 'Backup #' . $id, true, 'Configuration snapshot stored.');
        return $id;
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
