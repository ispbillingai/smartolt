<?php
final class EthernetMonitor
{
    public function __construct(private OltManager $manager) {}

    public function read(int $id, int $port): array
    {
        $onu = $this->manager->onu($id);
        if (!$onu) throw new InvalidArgumentException('ONU not found.');
        $types = array_column($this->manager->onuTypes(), null, 'id');
        $ports = (int)($types[$onu['onu_type_id']]['eth_ports'] ?? 1);
        if ($port < 1 || $port > $ports) throw new InvalidArgumentException('Choose an Ethernet port supported by the ONU type.');
        $sample = $this->manager->onuEthernetStats($id, $port);
        $this->manager->db()->prepare('INSERT INTO onu_ethernet_samples (onu_id,eth_port,received_bytes,sent_bytes,up_kbps,down_kbps,at) VALUES (?,?,?,?,?,?,?)')
            ->execute([$id,$port,$sample['received_bytes'],$sample['sent_bytes'],$sample['up_kbps'],$sample['down_kbps'],$sample['sampled_at']]);
        return $sample;
    }

    public function history(int $id, int $port): array
    {
        $query = $this->manager->db()->prepare('SELECT at, received_bytes, sent_bytes, up_kbps, down_kbps FROM onu_ethernet_samples WHERE onu_id=? AND eth_port=? AND at>=? ORDER BY id DESC LIMIT 500');
        $query->execute([$id, $port, date('Y-m-d H:i:s', time()-86400)]);
        return array_reverse($query->fetchAll());
    }

    public function latest(int $oltId = 0, int $zoneId = 0, string $search = '', int $hours = 24): array
    {
        $query = $this->manager->db()->prepare('SELECT e.*, u.description, u.sn, u.olt_id, o.name AS olt_name
            FROM onu_ethernet_samples e JOIN (SELECT MAX(id) id FROM onu_ethernet_samples GROUP BY onu_id,eth_port) last ON last.id=e.id
            JOIN onus u ON u.id=e.onu_id JOIN olts o ON o.id=u.olt_id
            WHERE (?=0 OR o.id=?) AND (?=0 OR u.zone_id=?) AND e.at>=?
            AND (?=\'\' OR u.description LIKE ? OR u.sn LIKE ? OR u.address LIKE ?)
            ORDER BY o.name,u.description,e.eth_port');
        $hours = in_array($hours, [24,168,720], true) ? $hours : 24;
        $like = '%' . str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $search) . '%';
        $query->execute([$oltId,$oltId,$zoneId,$zoneId,date('Y-m-d H:i:s',time()-$hours*3600),$search,$like,$like,$like]);
        return $query->fetchAll();
    }
}
