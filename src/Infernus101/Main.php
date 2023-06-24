<?php
declare(strict_types=1);
/*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU Lesser General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*/

namespace Infernus101;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat;
use pocketmine\utils\Config;
use pocketmine\event\Listener;
use onebone\economyapi\EconomyAPI;

class BountyPlugin extends PluginBase implements Listener
{
    /** @var \SQLite3 */
    private $db;

    public function onEnable(): void
    {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        $this->db = new \SQLite3($this->getDataFolder() . "bounty.db");
        $this->db->exec("CREATE TABLE IF NOT EXISTS bounty (player TEXT PRIMARY KEY, money INTEGER);");

        $this->getLogger()->info("BountyPlugin has been enabled.");
    }

    public function onDisable(): void
    {
        $this->db->close();
        $this->getLogger()->info("BountyPlugin has been disabled.");
    }

    private function bountyExists(string $player): bool
    {
        $result = $this->db->query("SELECT * FROM bounty WHERE player = '$player';");
        return $result->fetchArray(SQLITE3_ASSOC) !== false;
    }

    private function getBountyMoney(string $player): int
    {
        $result = $this->db->query("SELECT * FROM bounty WHERE player = '$player';");
        $resultArr = $result->fetchArray(SQLITE3_ASSOC);
        return (int)$resultArr["money"];
    }

    private function renderNametag(Player $player): void
    {
        if ($this->bountyExists($player->getName())) {
            $money = $this->getBountyMoney($player->getName());
            $player->setNameTag($player->getName() . "\n" . TextFormat::YELLOW . "Bounty: $" . $money);
        } else {
            $player->setNameTag($player->getName());
        }
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool
    {
        if (strtolower($command->getName()) === "bounty") {
            if (!isset($args[0]) || !isset($args[1])) {
                $sender->sendMessage(TextFormat::RED . "Usage: /bounty <player> <amount>");
                return true;
            }
            $player = $this->getServer()->getPlayer($args[0]);
            if (!$player instanceof Player) {
                $sender->sendMessage(TextFormat::RED . "The specified player is not online.");
                return true;
            }
            if (!is_numeric($args[1]) || $args[1] <= 0) {
                $sender->sendMessage(TextFormat::RED . "Please enter a valid bounty amount.");
                return true;
            }
            $amount = (int)$args[1];
            $money = EconomyAPI::getInstance()->myMoney($sender);
            if ($money >= $amount) {
                $bountyMoney = $this->getBountyMoney($player->getName());
                if ($bountyMoney >= $amount) {
                    $sender->sendMessage(TextFormat::RED . "The specified player already has a higher bounty.");
                    return true;
                }
                EconomyAPI::getInstance()->reduceMoney($sender, $amount);
                if ($this->bountyExists($player->getName())) {
                    $stmt = $this->db->prepare("UPDATE bounty SET money = :money WHERE player = :player;");
                    $stmt->bindValue(":player", $player->getName(), SQLITE3_TEXT);
                    $stmt->bindValue(":money", $amount, SQLITE3_INTEGER);
                    $stmt->execute();
                    $stmt->close();
                } else {
                    $stmt = $this->db->prepare("INSERT INTO bounty (player, money) VALUES (:player, :money);");
                    $stmt->bindValue(":player", $player->getName(), SQLITE3_TEXT);
                    $stmt->bindValue(":money", $amount, SQLITE3_INTEGER);
                    $stmt->execute();
                    $stmt->close();
                }
                $this->renderNametag($player);
                $sender->sendMessage(TextFormat::GREEN . "You have placed a bounty of $" . $amount . " on " . $player->getName() . ".");
            } else {
                $sender->sendMessage(TextFormat::RED . "You don't have enough money to place a bounty.");
            }
            return true;
        }
        return false;
    }

    public function onPlayerDeath(PlayerDeathEvent $event): void
    {
        $player = $event->getPlayer();
        $playerName = $player->getName();
        if ($this->bountyExists($playerName)) {
            $bountyMoney = $this->getBountyMoney($playerName);
            EconomyAPI::getInstance()->addMoney($player->getLastDamageCause()->getDamager(), $bountyMoney);
            $this->db->exec("DELETE FROM bounty WHERE player = '$playerName';");
            $this->renderNametag($player);
            $player->sendMessage(TextFormat::GREEN . "You have received a bounty of $" . $bountyMoney . ".");
        }
    }
}
