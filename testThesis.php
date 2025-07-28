<?php

namespace Tests\Unit\Events\Listeners\Processors;

use AcademicObligations\ThesisNotifications\Events\Listeners\Processors\ThesisStateValidator;
use PHPUnit\Framework\TestCase;

class ThesisStateValidatorTest extends TestCase
{
  public function testIsTD1WithPTD()
  {
    $this->assertTrue(ThesisStateValidator::isTD1WithPTD('TD1', 'PTD'));
    $this->assertFalse(ThesisStateValidator::isTD1WithPTD('TD1', 'PTDD'));
    $this->assertFalse(ThesisStateValidator::isTD1WithPTD('TD0', 'PTD'));
    $this->assertFalse(ThesisStateValidator::isTD1WithPTD('TD2', 'PTD'));
  }

  public function testIsTD1WithPTDD()
  {
    $this->assertTrue(ThesisStateValidator::isTD1WithPTDD('TD1', 'PTDD'));
    $this->assertFalse(ThesisStateValidator::isTD1WithPTDD('TD1', 'PTD'));
    $this->assertFalse(ThesisStateValidator::isTD1WithPTDD('TD0', 'PTDD'));
    $this->assertFalse(ThesisStateValidator::isTD1WithPTDD('TD2', 'PTDD'));
  }

  public function testIsTD0()
  {
    $this->assertTrue(ThesisStateValidator::isTD0('TD0', null));
    $this->assertFalse(ThesisStateValidator::isTD0('TD0', 'PTD'));
    $this->assertFalse(ThesisStateValidator::isTD0('TD1', null));
    $this->assertFalse(ThesisStateValidator::isTD0('TD2', null));
  }

  public function testIsTD2()
  {
    $this->assertTrue(ThesisStateValidator::isTD2('TD2', null));
    $this->assertFalse(ThesisStateValidator::isTD2('TD2', 'PTD'));
    $this->assertFalse(ThesisStateValidator::isTD2('TD0', null));
    $this->assertFalse(ThesisStateValidator::isTD2('TD1', null));
  }
}
