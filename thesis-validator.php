<?php

namespace AcademicObligations\ThesisNotifications\Events\Listeners\Processors;

use AcademicObligations\ThesisNotifications\DB\Enums\ThesisStatus;
use AcademicObligations\ThesisNotifications\DB\Enums\ThesisSubStatus;

/**
 * Clase para validar los estados de las tesis
 */
class ThesisStateValidator
{
  /**
   * Verifica si el estado y subestado corresponden a TD1 con PTD
   */
  public static function isTD1WithPTD(string $status, ?string $subStatus): bool
  {
    return $status === ThesisStatus::TD1->value && $subStatus === ThesisSubStatus::PTD->value;
  }

  /**
   * Verifica si el estado y subestado corresponden a TD1 con PTDD
   */
  public static function isTD1WithPTDD(string $status, ?string $subStatus): bool
  {
    return $status === ThesisStatus::TD1->value && $subStatus === ThesisSubStatus::PTDD->value;
  }

  /**
   * Verifica si el estado es TD0 (sin subestados)
   */
  public static function isTD0(string $status, ?string $subStatus): bool
  {
    return $status === ThesisStatus::TD0->value && $subStatus === null;
  }

  /**
   * Verifica si el estado es TD2 (sin subestados)
   */
  public static function isTD2(string $status, ?string $subStatus): bool
  {
    return $status === ThesisStatus::TD2->value && $subStatus === null;
  }
}
