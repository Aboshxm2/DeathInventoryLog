<?php

declare(strict_types=1);

namespace muqsit\deathinventorylog\db;

use Closure;
use muqsit\deathinventorylog\Loader;
use muqsit\deathinventorylog\util\InventorySerializer;
use pocketmine\uuid\UUID;
use poggit\libasynql\DataConnector;
use poggit\libasynql\libasynql;

final class SQLite3Database implements Database{

	/**
	 * @param Loader $plugin
	 * @param mixed[] $configuration
	 * @return self
	 *
	 * @phpstan-param array{file: string} $configuration
	 */
	public static function create(Loader $plugin, array $configuration) : self{
		return new self($plugin, $configuration["file"]);
	}

	/** @var DataConnector */
	private $connector;

	private function __construct(Loader $plugin, string $file){
		$this->connector = libasynql::create($plugin, [
			"type" => "sqlite",
			"sqlite" => ["file" => $file]
		], ["sqlite" => "db/sqlite.sql"]);
		$this->connector->executeGeneric("deathinventorylog.init");
		$this->connector->waitAll();
	}

	public function store(UUID $player, DeathInventory $inventory, Closure $callback) : void{
		$this->connector->executeInsert("deathinventorylog.save", [
			"uuid" => $player->toBinary(),
			"time" => time(),
			"inventory" => InventorySerializer::serialize($inventory->getInventoryContents()),
			"armor_inventory" => InventorySerializer::serialize($inventory->getArmorContents())
		], static function(int $insert_id, int $affected_rows) use($callback) : void{ $callback($insert_id); });
	}

	public function retrieve(int $id, Closure $callback) : void{
		$this->connector->executeSelect("deathinventorylog.retrieve", ["id" => $id], static function(array $rows) use($callback) : void{
			$row = current($rows);
			if($row !== false){
				$callback(new DeathInventoryLog(
					$row["id"],
					UUID::fromBinary($row["uuid"]),
					new DeathInventory(
						InventorySerializer::deSerialize($row["inventory"]),
						InventorySerializer::deSerialize($row["armor_inventory"])
					),
					$row["time"]
				));
			}else{
				$callback(null);
			}
		});
	}

	public function retrievePlayer(UUID $player, int $offset, int $length, Closure $callback) : void{
		$this->connector->executeSelect("deathinventorylog.retrieve_player", [
			"uuid" => $player->toBinary(),
			"offset" => $offset,
			"length" => $length
		], static function(array $rows) use($callback) : void{
			$result = [];
			foreach($rows as $row){
				$result[] = new DeathInventoryLog(
					$row["id"],
					UUID::fromBinary($row["uuid"]),
					new DeathInventory(
						InventorySerializer::deSerialize($row["inventory"]),
						InventorySerializer::deSerialize($row["armor_inventory"])
					),
					$row["time"]
				);
			}
			$callback($result);
		});
	}

	public function close() : void{
		$this->connector->close();
	}
}