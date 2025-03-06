<?php

declare(strict_types=1);

namespace solo\hotairballoon;

use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Human;
use pocketmine\entity\Location;
use pocketmine\entity\Skin;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\SetActorLinkPacket;
use pocketmine\network\mcpe\protocol\types\entity\EntityLink;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;

abstract class Vehicle extends Human {

    public static array $ridingEntities = [];

    protected ?Player $rider = null;
    protected float $riderOffset = -0.8;
    protected float $gravity = 0.08;
    protected float $drag = 0.02;
    protected float $baseOffset = 1.62;

    public function __construct(Location $location, ?Skin $skin = null, ?CompoundTag $nbt = null) {
        if ($skin === null) {
            $skin = $this->getSkin();
        }
        parent::__construct($location, $skin, $nbt);
    }

    protected function getInitialSizeInfo(): EntitySizeInfo {
        return new EntitySizeInfo(1.8, 0.6);
    }

    public function getName(): string {
        return $this->getNameTag();
    }

    public function getRider(): ?Player {
        return $this->rider;
    }

    public function isRiding(): bool {
        return $this->rider instanceof Player;
    }

    public function ride(Player $player): bool {
        if (isset(self::$ridingEntities[$player->getName()])) {
            $player->sendPopup(TextFormat::RED . "You are already riding");
            return false;
        }
        
        if ($this->rider instanceof Player) {
            $player->sendPopup(TextFormat::RED . "Someone is already riding");
            return false;
        }
        
        $this->rider = $player;
        self::$ridingEntities[$player->getName()] = $this;

        $this->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::SADDLED, true);
        $player->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::RIDING, true);
        $player->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::WASD_CONTROLLED, true);
        
        foreach ($this->getViewers() as $viewer) {
            $this->sendLink($viewer);
        }
        
        $player->sendPopup(TextFormat::AQUA . "Press jump or sneak to throw off");
        return true;
    }

    public function input(float $motionX, float $motionY): void {
    }

    public function dismount(bool $immediate = false): void {
        if (!$this->rider instanceof Player) return;

        $this->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::SADDLED, false);
        $this->rider->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::RIDING, false);
        $this->rider->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::WASD_CONTROLLED, false);
        
        foreach ($this->getViewers() as $viewer) {
            $this->sendLink($viewer, EntityLink::TYPE_REMOVE, $immediate);
        }

        unset(self::$ridingEntities[$this->rider->getName()]);
        $this->rider = null;
    }

    public function flagForDespawn(): void {
        $this->dismount(true);
        parent::flagForDespawn();
    }

    public function getRiderSeatPosition(int $seatNumber = 0): Vector3 {
        return new Vector3(0, $this->size->getHeight() * 0.75 + $this->riderOffset, 0);
    }

    public function sendLink(Player $player, int $type = EntityLink::TYPE_RIDER, bool $immediate = false): void {
        if (!$this->rider instanceof Player) return;

        if (!$player->canSee($this->rider)) {
            $this->rider->despawnFrom($player);
            $this->rider->spawnTo($player);
        }

        $from = $this->getId();
        $to = $this->rider->getId();

        $pk = new SetActorLinkPacket();
        $pk->link = new EntityLink($from, $to, $type, $immediate, true, 0.0);
        $player->getNetworkSession()->sendDataPacket($pk);
    }

    protected function syncNetworkData(EntityMetadataCollection $properties): void {
        parent::syncNetworkData($properties);
        
        $properties->setString(
            EntityMetadataProperties::INTERACTIVE_TAG,
            "Ride"
        );
        
        $properties->setVector3(
            EntityMetadataProperties::RIDER_SEAT_POSITION,
            $this->getRiderSeatPosition()
        );
        
        $properties->setFloat(
            EntityMetadataProperties::CONTROLLING_RIDER_SEAT_NUMBER,
            0
        );
    }

    public function getOffsetPosition(Vector3 $vector): Vector3 {
        return $vector->add(0, $this->baseOffset, 0);
    }
}
