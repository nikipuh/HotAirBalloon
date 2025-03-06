<?php

declare(strict_types=1);

namespace solo\hotairballoon;

use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Location;
use pocketmine\entity\Skin;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;

class AirVehicle extends Vehicle {

    protected float $flightSpeed = 0.4;
    protected float $verticalSpeed = 0.15;

    public function __construct(Location $location, ?Skin $skin = null, ?CompoundTag $nbt = null) {
        parent::__construct($location, $skin, $nbt);
    }

    protected function getInitialSizeInfo(): EntitySizeInfo {
        return new EntitySizeInfo(1.8, 0.6);
    }

    protected function initEntity(CompoundTag $nbt): void {
        parent::initEntity($nbt);
        
        $this->gravityEnabled = false;
        $this->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::AFFECTED_BY_GRAVITY, false);
    }

    public function getSkin(): Skin {
        return HotAirBalloon::$resources->getSkin("AirVehicle") ?? new Skin(
            "Standard_Custom",
            str_repeat("\x00", 64 * 64 * 4),
            "",
            "geometry.humanoid.custom"
        );
    }

    public function input(float $motionX, float $motionY): void {
        if ($motionX > 0.01) {
            $this->setRotation($this->getLocation()->getYaw() - 5, $this->getLocation()->getPitch());
        } elseif ($motionX < -0.01) {
            $this->setRotation($this->getLocation()->getYaw() + 5, $this->getLocation()->getPitch());
        }

        if (abs($motionY) > 0.01) {
            $pos = $this->getPosition();
            $yaw = $this->getLocation()->getYaw();
            $pitch = $this->getLocation()->getPitch();
            
            if ($this->isRiding() && $this->getRider() !== null) {
                $pitch = $this->getRider()->getLocation()->getPitch();
            }
            
            $yawRad = deg2rad($yaw);
            
            $dx = -sin($yawRad) * $motionY * $this->flightSpeed;
            $dz = cos($yawRad) * $motionY * $this->flightSpeed;
            
            $dy = 0;
            if ($pitch < -25) {
                $dy = $this->verticalSpeed;
            } elseif ($pitch > 25) {
                $dy = -$this->verticalSpeed;
            }
            
            $this->teleport(new Vector3(
                $pos->x + $dx,
                $pos->y + $dy,
                $pos->z + $dz
            ));
        }
    }
    
    public function handleJumpSneak(bool $isJumping, bool $isSneak): void {
        $pos = $this->getPosition();
        
        if ($isJumping) {
            $this->teleport(new Vector3(
                $pos->x,
                $pos->y + $this->verticalSpeed,
                $pos->z
            ));
        } elseif ($isSneak) {
            $this->teleport(new Vector3(
                $pos->x,
                $pos->y - $this->verticalSpeed,
                $pos->z
            ));
        }
    }

    public function entityBaseTick(int $tickDiff = 1): bool {
        $hasUpdate = parent::entityBaseTick($tickDiff);

        if (!$this->isFlaggedForDespawn() && $this->isRiding()) {
            $this->getRider()->resetFallDistance();
        }
        
        return $hasUpdate;
    }
}
