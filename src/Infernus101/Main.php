<?php

/*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU Lesser General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*/

namespace Infernus101;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\entity\EntityRegainHealthEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\plugin\PluginBase;
use pocketmine\Player;
use pocketmine\Server;
use pocketmine\command\{Command, CommandSender};
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use onebone\economyapi\EconomyAPI;

class Main extends PluginBase implements Listener{
    public $db;

    public function onEnable(){
        $this->getLogger()->info("§b§lLoaded Bounty by Zeao succesfully.");
        $files = ["config.yml"];
        foreach($files as $file){
            if(!file_exists($this->getDataFolder() . $file)) {
                @mkdir($this->getDataFolder());
                file_put_contents($this->getDataFolder() . $file, $this->getResource($file));
            }
        }
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->cfg = new Config($this->getDataFolder() . "config.yml", Config::YAML);
        $this->db = new \SQLite3($this->getDataFolder() . "bounty.db");
        $this->db->exec("CREATE TABLE IF NOT EXISTS bounty (player TEXT PRIMARY KEY COLLATE NOCASE, money INT);");
    }

    public function bountyExists($player){
        $result = $this->db->query("SELECT * FROM bounty WHERE player='$player';");
        $array = $result->fetchArray(SQLITE3_ASSOC);
        return !empty($array);
    }

    public function getBountyMoney($player){
        $result = $this->db->query("SELECT * FROM bounty WHERE player = '$player';");
        $resultArr = $result->fetchArray(SQLITE3_ASSOC);
        return (int) $resultArr["money"];
    }

    public function onEntityDamage(EntityDamageEvent $event){
        $entity = $event->getEntity();
        if($entity instanceof Player){
            $player = $entity->getPlayer();
            if($this->cfg->get("bounty_stats") == 1 || $this->cfg->get("health_stats") == 1){
                $this->renderNametag($player);
            }
        }
    }

    public function onEntityRegainHealth(EntityRegainHealthEvent $event){
        $entity = $event->getEntity();
        if($entity instanceof Player){
            $player = $entity->getPlayer();
            if($this->cfg->get("bounty_stats") == 1 || $this->cfg->get("health_stats") == 1){
                $this->renderNametag($player);
            }
        }
    }

    public function onJoin(PlayerJoinEvent $event){
        $player = $event->getPlayer();
        if($this->cfg->get("bounty_stats") == 1 || $this->cfg->get("health_stats") == 1){
            $this->renderNametag($player);
        }
    }

    public function getBountyMoney2($player){
        $result = $this->db->query("SELECT * FROM bounty WHERE player = '$player';");
        $resultArr = $result->fetchArray(SQLITE3_ASSOC);
        return (int) $resultArr["money"];
    }

    public function renderNametag($player){
        if($this->bountyExists($player->getName())){
            $money = $this->getBountyMoney($player->getName());
            $player->setNameTag($player->getName() . "\n" . TextFormat::YELLOW . "Bounty: $" . $money);
        }else{
            $player->setNameTag($player->getName());
        }
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool{
        if(strtolower($command->getName()) === "bounty"){
            if(!isset($args[0]) or !isset($args[1])){
                $sender->sendMessage(TextFormat::RED . "Usage: /bounty <player> <amount>");
                return true;
            }
            $player = $this->getServer()->getPlayer($args[0]);
            if(!$player instanceof Player){
                $sender->sendMessage(TextFormat::RED . "The specified player is not online.");
                return true;
            }
            if(!is_numeric($args[1]) or $args[1] <= 0){
                $sender->sendMessage(TextFormat::RED . "Please enter a valid bounty amount.");
                return true;
            }
            $amount = (int) $args[1];
            $money = EconomyAPI::getInstance()->myMoney($sender);
            if($money >= $amount){
                $bountyMoney = $this->getBountyMoney2($player->getName());
                if($bountyMoney >= $amount){
                    $sender->sendMessage(TextFormat::RED . "The specified player already has a higher bounty.");
                    return true;
                }
                EconomyAPI::getInstance()->reduceMoney($sender, $amount);
                if($this->bountyExists($player->getName())){
                    $stmt = $this->db->prepare("UPDATE bounty SET money = :money WHERE player = :player;");
                    $stmt->bindValue(":money", $amount);
                    $stmt->bindValue(":player", $player->getName());
                    $stmt->execute();
                }else{
                    $stmt = $this->db->prepare("INSERT OR REPLACE INTO bounty (player, money) VALUES (:player, :money);");
                    $stmt->bindValue(":player", $player->getName());
                    $stmt->bindValue(":money", $amount);
                    $stmt->execute();
                }
                $this->renderNametag($player);
                $sender->sendMessage(TextFormat::GREEN . "You have successfully set a $" . $amount . " bounty on " . $player->getName() . ".");
            }else{
                $sender->sendMessage(TextFormat::RED . "You don't have enough money to set this bounty.");
            }
            return true;
        }
        return false;
    }

    public function onPlayerDeath(PlayerDeathEvent $event){
        $player = $event->getPlayer();
        if($this->bountyExists($player->getName())){
            $money = $this->getBountyMoney($player->getName());
            $killer = $player->getLastDamageCause()->getDamager();
            if($killer instanceof Player){
                EconomyAPI::getInstance()->addMoney($killer, $money);
                $this->db->exec("DELETE FROM bounty WHERE player = '{$player->getName()}';");
                $this->renderNametag($player);
                $killer->sendMessage(TextFormat::GREEN . "You have claimed a $" . $money . " bounty on " . $player->getName() . ".");
            }
        }
    }

    public function onDisable(){
        $this->db->close();
    }
}
