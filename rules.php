<?php

namespace AcademicObligations\ThesisDocuments\Models;

use AcademicObligations\ThesisDocuments\Models\ValueObjects\RestrictionRule;

class Rules
{
  /**
   * @var array
   */
  private $restrictions = [];

  /**
   * @var array
   */
  private $multipleTypesAllowed = ['pdf', 'doc', 'docx', 'odt'];

  /**
   * @var array
   */
  private $onlyAllowedTypes = ['pdf'];

  public function __construct()
  {
    $this->initializeDefaultRules();
  }

  /**
   * Inicializa las reglas por defecto
   */
  private function initializeDefaultRules(): void
  {
    $this->restrictions = [
      'TD0' => new RestrictionRule(
        'Ficha Doctoral',
        1,
        15,
        'mb',
        $this->onlyAllowedTypes,
        1
      ),
      'TD1' => [
        'subState' => [
          'PTD1' => new RestrictionRule(
            'Propuesta Tesis Doctoral',
            1,
            15,
            'mb',
            $this->onlyAllowedTypes,
            1
          ),
          'PTDD1' => new RestrictionRule(
            'Propuesta Tesis Doctoral Definitiva',
            2,
            15,
            'mb',
            $this->multipleTypesAllowed,
            2
          ),
        ]
      ]
    ];
  }

  /**
   * Constructor alternativo para crear reglas personalizadas
   */
  public static function withCustomRules(array $restrictions): self
  {
    $instance = new self();
    $instance->restrictions = $restrictions;
    return $instance;
  }

  /**
   * Agregar una nueva regla
   */
  public function addRule(string $key, $rule): void
  {
    $this->restrictions[$key] = $rule;
  }

  /**
   * Agregar una regla de subestado
   */
  public function addSubStateRule(string $parentKey, string $subKey, RestrictionRule $rule): void
  {
    if (!isset($this->restrictions[$parentKey])) {
      $this->restrictions[$parentKey] = ['subState' => []];
    }

    if (!isset($this->restrictions[$parentKey]['subState'])) {
      $this->restrictions[$parentKey]['subState'] = [];
    }

    $this->restrictions[$parentKey]['subState'][$subKey] = $rule;
  }

  /**
   * Obtener todas las restricciones
   */
  public function getAllRestrictions(): array
  {
    return $this->restrictions;
  }

  /**
   * @param mixed $status
   * @param mixed $subStatus
   */
  public function getStatusOrSubStatus($status, $subStatus)
  {
    $getStatus = isset($this->restrictions[$status]) ? $this->restrictions[$status] : null;
    $getSubStatus = (is_array($getStatus) && isset($getStatus['subState'][$subStatus]))
      ? $getStatus['subState'][$subStatus]
      : null;

    if (!$getSubStatus) {
      return $getStatus;
    }
    return $getSubStatus;
  }

  /**
   * Verificar si existe una regla para el status/substatus dado
   */
  public function hasRule(string $status, ?string $subStatus = null): bool
  {
    if (!isset($this->restrictions[$status])) {
      return false;
    }

    if ($subStatus === null) {
      return true;
    }

    $statusRule = $this->restrictions[$status];
    return is_array($statusRule) &&
      isset($statusRule['subState']) &&
      isset($statusRule['subState'][$subStatus]);
  }
}
