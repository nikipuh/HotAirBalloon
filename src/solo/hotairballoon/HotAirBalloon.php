<?php

declare(strict_types=1);

namespace solo\hotairballoon;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\PluginCommand;
use pocketmine\entity\EntityDataHelper;
use pocketmine\entity\EntityFactory;
use pocketmine\entity\Human;
use pocketmine\entity\Skin;
use pocketmine\event\Listener;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityTeleportEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\InteractPacket;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemOnEntityTransactionData;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat;
use pocketmine\world\World;

class HotAirBalloon extends PluginBase {

    public static string $prefix = TextFormat::BOLD . TextFormat::AQUA . "[HotAirBalloon] " . TextFormat::RESET . TextFormat::GRAY;

    public static Resources $resources;
    private static HotAirBalloon $instance;
    
    public static function getInstance(): HotAirBalloon {
        return self::$instance;
    }

    public function onLoad(): void {
        self::$instance = $this;
        self::$resources = new Resources($this);

        EntityFactory::getInstance()->register(AirVehicle::class, function(World $world, CompoundTag $nbt): AirVehicle {
            $skin = HotAirBalloon::$resources->getSkin("AirVehicle") ?? new Skin(
                "Standard_Custom",
                str_repeat("\x00", 64 * 64 * 4),
                "",
                "geometry.humanoid.custom"
            );
            
            return new AirVehicle(EntityDataHelper::parseLocation($nbt, $world), $skin, $nbt);
        }, ['AirVehicle', 'hotairballoon:airvehicle']);
    }
    
    public function onEnable(): void {
        $this->getServer()->getCommandMap()->register('hotairballoon', new class($this) extends Command {
            private HotAirBalloon $plugin;

            public function __construct(HotAirBalloon $plugin) {
                parent::__construct(
                    "vehicle", 
                    "HotAirBalloon vehicle commands", 
                    "/vehicle <registerskin|create>"
                );
                $this->setPermission("hotairballoon.command");
                $this->plugin = $plugin;
            }

            public function execute(CommandSender $sender, string $commandLabel, array $args): bool {
                if (!$this->testPermission($sender)) {
                    return false;
                }

                if (count($args) === 0) {
                    $sender->sendMessage(HotAirBalloon::$prefix . "Usage: " . $this->getUsage());
                    return false;
                }

                switch ($args[0]) {
                    case "registerskin":
                        if (!$sender instanceof Player) {
                            $sender->sendMessage(HotAirBalloon::$prefix . "You can't run this command in console.");
                            return false;
                        }

                        $name = isset($args[1]) ? $args[1] : "";
                        if (empty($name)) {
                            $sender->sendMessage(HotAirBalloon::$prefix . "Usage: /vehicle registerskin <n>");
                            return false;
                        }

                        $skin = $sender->getSkin();
                        HotAirBalloon::$resources->setSkin($name, $skin);
                        $sender->sendMessage(HotAirBalloon::$prefix . "You've registered your skin.");
                        return true;

                    case "create":
                        if (!$sender instanceof Player) {
                            $sender->sendMessage(HotAirBalloon::$prefix . "You can't run this command in console.");
                            return false;
                        }
                        
                        $nbt = CompoundTag::create();
                        
                        $location = $sender->getLocation();
                        
                        $skin = HotAirBalloon::$resources->getSkin("AirVehicle") ?? new Skin(
                            "Standard_Custom",
                            str_repeat("\x00", 64 * 64 * 4),
                            "",
                            "geometry.humanoid.custom"
                        );
                        
                        $entity = new AirVehicle($location, $skin, $nbt);
                        $entity->spawnToAll();

                        $sender->sendMessage(HotAirBalloon::$prefix . "Successfully created a vehicle.");
                        return true;

                    default:
                        $sender->sendMessage(HotAirBalloon::$prefix . "Unknown subcommand: " . $args[0]);
                        return false;
                }
            }
        });

        $this->getServer()->getPluginManager()->registerEvents(new VehicleHandler($this), $this);
    }

    public function onDisable(): void {
        self::$resources->save();
    }
}

class Resources {
    private string $path;
    private array $skins = [];

    public function __construct(HotAirBalloon $plugin) {
        $path = $plugin->getDataFolder();
        @mkdir($path);
        $plugin->saveResource("skins.json");

        $this->path = $path;
        $this->skins = $this->loadFromFile($this->path . "skins.json");
        foreach ($this->skins as $name => $skin) {
            $this->skins[$name] = new Skin(
                $skin["skinId"],
                base64_decode($skin["skinData"]),
                base64_decode($skin["capeData"]),
                $skin["geometryName"],
                $skin["geometryData"]
            );
        }
    }

    protected function loadFromFile(string $file): array {
        if (!file_exists($file)) return [];
        return json_decode(file_get_contents($file), true) ?? [];
    }

    protected function saveToFile(string $file, array $data): void {
        file_put_contents($file, json_encode($data));
    }

    public function getSkin(string $name): ?Skin {
        return $this->skins[$name] ?? null;
    }

    public function setSkin(string $name, Skin $skin): void {
        $this->skins[$name] = $skin;
    }

    public function save(): void {
        if (empty($this->skins)) return;

        $saveSkins = [];
        foreach ($this->skins as $name => $skin) {
            $saveSkins[$name] = [
                "skinId" => $skin->getSkinId(),
                "skinData" => base64_encode($skin->getSkinData()),
                "capeData" => base64_encode($skin->getCapeData()),
                "geometryName" => $skin->getGeometryName(),
                "geometryData" => $skin->getGeometryData()
            ];
        }
        $this->saveToFile($this->path . "skins.json", $saveSkins);
    }
}

class VehicleHandler implements Listener {
    private HotAirBalloon $plugin;
    
    private array $playerStates = [];

    public function __construct(HotAirBalloon $owner) {
        $this->plugin = $owner;
    }

    public function onDataPacketReceive(DataPacketReceiveEvent $event): void {
        $packet = $event->getPacket();
        $player = $event->getOrigin()->getPlayer();
        if ($player === null) return;

        if ($packet instanceof InteractPacket) {
            if ($packet->action === InteractPacket::ACTION_LEAVE_VEHICLE) {
                $target = $player->getWorld()->getEntity($packet->targetActorRuntimeId);
                if ($target instanceof Vehicle && $target->getRider() === $player) {
                    $target->dismount();
                    $event->cancel();
                }
            }
        } elseif ($packet instanceof InventoryTransactionPacket) {
            if ($packet->trData instanceof UseItemOnEntityTransactionData) {
                $target = $player->getWorld()->getEntity($packet->trData->getActorRuntimeId());
                if ($target instanceof Vehicle && $packet->trData->getActionType() === UseItemOnEntityTransactionData::ACTION_INTERACT) {
                    $this->plugin->getLogger()->debug("Player {$player->getName()} is attempting to ride a vehicle");
                    $result = $target->ride($player);
                    $this->plugin->getLogger()->debug("Ride result: " . ($result ? "SUCCESS" : "FAILED"));
                    $event->cancel();
                }
            }
        }
    }
    
    public function onPlayerMove(PlayerMoveEvent $event): void {
        $player = $event->getPlayer();
        $playerName = $player->getName();
        
        if (!isset(Vehicle::$ridingEntities[$playerName])) {
            return;
        }
        
        $riding = Vehicle::$ridingEntities[$playerName];
        
        $yaw = $player->getLocation()->getYaw();
        $pitch = $player->getLocation()->getPitch();
        
        if (!isset($this->playerStates[$playerName])) {
            $this->playerStates[$playerName] = [
                'lastYaw' => $yaw,
                'lastPitch' => $pitch,
                'isJumping' => false,
                'isSneak' => false
            ];
        }
        
        $yawChange = $yaw - $this->playerStates[$playerName]['lastYaw'];
        if (abs($yawChange) > 0.5) {
            if ($yawChange > 0) {
                $this->plugin->getLogger()->debug("Player $playerName rotated LEFT");
                $riding->input(-0.5, 0);
            } 
            else {
                $this->plugin->getLogger()->debug("Player $playerName rotated RIGHT");
                $riding->input(0.5, 0);
            }
        }
        
        if ($pitch < -25 && $this->playerStates[$playerName]['lastPitch'] >= -25) {
            $this->plugin->getLogger()->debug("Player $playerName looking UP");
            if ($riding instanceof AirVehicle) {
                $riding->handleJumpSneak(true, false);
            }
        } 
        else if ($pitch > 25 && $this->playerStates[$playerName]['lastPitch'] <= 25) {
            $this->plugin->getLogger()->debug("Player $playerName looking DOWN");
            if ($riding instanceof AirVehicle) {
                $riding->handleJumpSneak(false, true);
            }
        }
        
        $isSneak = $player->isSneaking();
        if ($isSneak && !$this->playerStates[$playerName]['isSneak']) {
            $this->plugin->getLogger()->debug("Player $playerName started SNEAKING");
            
            $riding->dismount();
        }
        
        $this->playerStates[$playerName]['lastYaw'] = $yaw;
        $this->playerStates[$playerName]['lastPitch'] = $pitch;
        $this->playerStates[$playerName]['isSneak'] = $isSneak;
        
        if ($event->getFrom()->distance($event->getTo()) > 0.01) {
            $directionVector = $event->getTo()->subtractVector($event->getFrom());
            
            $playerDirection = (new Vector3(-sin($yaw * M_PI / 180), 0, cos($yaw * M_PI / 180)))->normalize();
            $dotProduct = $directionVector->dot($playerDirection);
            
            if (abs($dotProduct) > 0.01) {
                $this->plugin->getLogger()->debug("Player $playerName moving: " . ($dotProduct > 0 ? "FORWARD" : "BACKWARD"));
                $riding->input(0, $dotProduct > 0 ? 1.0 : -1.0);
            }
        }
        
        if (abs($pitch) < 15) {
            $riding->input(0, 0.2);
        }
    }

    public function onTeleport(EntityTeleportEvent $event): void {
        $entity = $event->getEntity();
        if ($entity instanceof Player) {
            $playerName = $entity->getName();
            if (isset(Vehicle::$ridingEntities[$playerName])) {
                $riding = Vehicle::$ridingEntities[$playerName];
                $riding->dismount();
            }
        }
    }

    public function onPlayerQuit(PlayerQuitEvent $event): void {
        $playerName = $event->getPlayer()->getName();
        if (isset(Vehicle::$ridingEntities[$playerName])) {
            $riding = Vehicle::$ridingEntities[$playerName];
            $riding->dismount();
        }
        
        if (isset($this->playerStates[$playerName])) {
            unset($this->playerStates[$playerName]);
        }
    }
    
    public function onEntityDamage(EntityDamageByEntityEvent $event): void {
        $entity = $event->getEntity();
        
        if ($entity instanceof Vehicle && !$entity->isRiding()) {
            $this->plugin->getLogger()->debug("Vehicle hit, handling manual movement");
            $event->cancel();
            
            if ($entity instanceof AirVehicle) {
                $damager = $event->getDamager();
                if ($damager instanceof Entity) {
                    $direction = $entity->getPosition()->subtractVector($damager->getPosition())->normalize();
                    $entity->knockBack($direction->x, $direction->z, 0.5, 0.3);
                }
            }
        }
    }
}
