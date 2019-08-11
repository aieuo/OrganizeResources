<?php
namespace aieuo;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\Player;
use pocketmine\block\Block;
use pocketmine\utils\Config;
use pocketmine\math\Vector3;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;

class OrganizeResources extends PluginBase implements Listener{
    public function onEnable() {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        if (!file_exists($this->getDataFolder())) @mkdir($this->getDataFolder(), 0721, true);
        $this->config = new Config($this->getDataFolder()."config.yml", Config::YAML);
        $this->setting = new Config($this->getDataFolder()."setting.yml", Config::YAML, [
            "wait" => 60,
            "sneak" => true
        ]);
        $this->wait = $this->setting->get("wait");
        $this->sneak = (int)$this->setting->get("sneak");
    }

    public function onDisable() {
        $this->setting->save();
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        $cmd = $command->getName();
        if ($cmd == "organize") {
            if (!$sender instanceof Player) {
                $sender->sendMessage("コンソールからは使用できません");
                return true;
            }
            if (!$sender->isOp()) return false;
            if (!isset($args[0])) return false;
            $name = $sender->getName();
            switch ($args[0]) {
                case "setting":
                    if (!isset($args[2])) {
                        $sender->sendMessage("/organize setting <wait | sneak>");
                        return true;
                    }
                    switch ($args[1]) {
                        case 'wait':
                            $wait = (int)$args[2];
                            if ($wait < 0) {
                                $sender->sendMessage("0秒以上で指定してください");
                                return true;
                            }
                            $this->setting->set("wait", $wait);
                            $sender->sendMessage($wait."秒に設定しました");
                            $this->wait = $wait;
                            break;
                        case 'sneak':
                            if ($args[2] == "on") {
                                $this->setting->set("sneak", true);
                                $sender->sendMessage("スニークしないと整理できないようになりました");
                                $this->sneak = true;
                            } elseif ($args[2] == "off") {
                                $this->setting->set("sneak", false);
                                $sender->sendMessage("スニークしなくても整理できるようになりました");
                                $this->sneak = false;
                            }
                            break;
                        default:
                            $sender->sendMessage("/reso setting <wait | sneak>");
                            break;
                    }
                    return true;
                case 'cancel':
                    unset($this->tap[$name], $this->break[$name], $this->pos1[$name], $this->pos2[$name]);
                    $sender->sendMessage("キャンセルしました");
                    return true;
                case 'pos1':
                    $this->break[$name] = "pos1";
                    $sender->sendMessage("設定する場所のブロックを壊してください");
                    return true;
                case 'pos2':
                    if (!isset($this->pos1[$name])) {
                        $sender->sendMessage("まずpos1を設定してください");
                        return true;
                    }
                    $this->break[$name] = "pos2";
                    $sender->sendMessage("設定する場所のブロックを壊してください");
                    return true;
                case 'add':
                    if (!isset($args[1])) {
                        $sender->sendMessage("/organize add <id>");
                        return true;
                    }
                    if (!isset($this->pos1[$name]) or !isset($this->pos2[$name])) {
                        $sender->sendMessage("まず/organize pos1, /organize pos2 で場所を設定してください");
                        return true;
                    }
                    if ($this->pos1[$name]["level"]->getFolderName() !== $this->pos2[$name]["level"]->getFolderName()) {
                        $sender->sendMessage("pos1とpos2は同じワールドに設定してください");
                        return true;
                    }
                    $this->tap[$name] = [
                        "type" => "add",
                        "id" => $args[1]
                    ];
                    $sender->sendMessage("追加する看板をタップしてください");
                    return true;
                case 'del':
                    $this->tap[$name]["type"] = "del";
                    $sender->sendMessage("削除する看板をタップしてください");
                    return true;
            }
            return true;
        }
    }

    public function onBreak(BlockBreakEvent $event) {
        $player = $event->getPlayer();
        $name = $player->getName();
        if (isset($this->break[$name])) {
            $block = $event->getBlock();
            $event->setCancelled();
            $type = $this->break[$name];
            $this->{$type}[$name] = [
                "x" => $block->x,
                "y" => $block->y,
                "z" => $block->z,
                "level" => $block->level
            ];
            $player->sendMessage($type."を設定しました(".$block->x.",".$block->y.",".$block->z.",".$block->level->getFolderName().")");
            unset($this->break[$name]);
        }
    }

    public function onTouch(PlayerInteractEvent $event) {
        $player = $event->getPlayer();
        $block = $event->getBlock();
        $name = $player->getName();
        if (($block->getId() == 63 or $block->getId() == 68)) {
            if (isset($this->tap[$name])) {
                $event->setCancelled();
                switch ($this->tap[$name]["type"]) {
                    case 'add':
                        $ids = array_map(function ($id) {
                            $id = explode(":", $id);
                            return $id[0].":".($id[1] ?? 0);
                        }, explode(",", $this->tap[$name]["id"]));
                        $this->config->set($block->x.",".$block->y.",".$block->z.",".$block->level->getFolderName(), [
                            "startx" => min($this->pos1[$name]["x"], $this->pos2[$name]["x"]),
                            "starty" => min($this->pos1[$name]["y"], $this->pos2[$name]["y"]),
                            "startz" => min($this->pos1[$name]["z"], $this->pos2[$name]["z"]),
                            "endx" => max($this->pos1[$name]["x"], $this->pos2[$name]["x"]),
                            "endy" => max($this->pos1[$name]["y"], $this->pos2[$name]["y"]),
                            "endz" => max($this->pos1[$name]["z"], $this->pos2[$name]["z"]),
                            "level" => $this->pos1[$name]["level"]->getFolderName(),
                            "ids" => $ids
                        ]);
                        $this->config->save();
                        $player->sendMessage("追加しました");
                        break;
                    case 'del':
                        $place = $block->x.",".$block->y.",".$block->z.",".$block->level->getFolderName();
                        if ($this->config->exists($place)) {
                            $this->config->remove($place);
                            $this->config->save();
                            $player->sendMessage("削除しました");
                        } else {
                            $player->sendMessage("その場所には登録されていません");
                        }
                        break;
                }
                unset($this->tap[$name]);
                return;
            }
            $place = $block->x.",".$block->y.",".$block->z.",".$block->level->getFolderName();
            if ($this->config->exists($place)) {
                if ($this->sneak == true and !$player->isSneaking()) {
                    $player->sendMessage("スニークしながらタップすると整理します");
                    return;
                }
                if ($this->wait !== 0) {
                    $time = $this->checkTime($player->getName(), $place);
                    if ($time !== true) {
                        $player->sendMessage($this->setting->get("wait")."秒以内に使用しています\nあと".round($time, 1)."秒お待ちください");
                        return;
                    }
                }
                $datas = $this->config->get($place);
                $this->organizeBlocks($datas);
            }
        }
    }

    public function checkTime($name, $type) {
        if (!isset($this->time[$name][$type])) {
            $this->time[$name][$type] = microtime(true);
            return true;
        }
        $time = microtime(true) -$this->time[$name][$type];
        if ($time <= (float)$this->setting->get("wait")) {
            return (float)$this->setting->get("wait") - $time;
        }
        $this->time[$name][$type] = microtime(true);
        return true;
    }

    public function setBlocks($datas, $place) {
        $sx = $datas["startx"];
        $sy = $datas["starty"];
        $sz = $datas["startz"];
        $ex = $datas["endx"];
        $ey = $datas["endy"];
        $ez = $datas["endz"];
        $level = $this->getServer()->getLevelByName($datas["level"]);
        $count = 0;
        $ids = array_map(function ($id) {
            return explode(":", $id);
        }, array_keys($place));
        $id = array_shift($ids);
        for ($y = $sy; $y <= $ey; $y++) {
            for ($z = $sz; $z <= $ez; $z++) {
                for ($x = $sx; $x <= $ex; $x++) {
                    if ($count >= $place[$id[0].":".$id[1]]) {
                        $count = 0;
                        $id = array_shift($ids);
                        if ($id === null or $place[$id[0].":".$id[1]] <= 0) return;
                    }
                    $block = $level->getBlock(new Vector3($x, $y, $z));
                    if ($block->getId() !== 0) continue;
                    $level->setBlock($block, Block::get($id[0], $id[1]));
                    $count ++;
                }
            }
        }
    }

    public function replaceBlocks($datas) {
        $sx = $datas["startx"];
        $sy = $datas["starty"];
        $sz = $datas["startz"];
        $ex = $datas["endx"];
        $ey = $datas["endy"];
        $ez = $datas["endz"];
        $level = $this->getServer()->getLevelByName($datas["level"]);
        $count = [];
        foreach ($datas["ids"] as $id) {
            $count[$id] = 0;
        }
        for ($x = $sx; $x <= $ex; $x++) {
            for ($z = $sz; $z <= $ez; $z++) {
                for ($y = $sy; $y <= $ey; $y++) {
                    $block = $level->getBlock(new Vector3($x, $y, $z));
                    if (!in_array($block->getId().":".$block->getDamage(), $datas["ids"])) continue;
                    $level->setBlock($block, Block::get(0));
                    $count[$block->getId().":".$block->getDamage()] ++;
                }
            }
        }
        return $count;
    }

    public function organizeBlocks($datas) {
        $count = $this->replaceBlocks($datas);
        $this->setBlocks($datas, $count);
    }
}