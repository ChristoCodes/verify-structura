<?php

namespace AcademicObligations\ThesisDocuments\Services;

use AcademicObligations\ThesisDocuments\Models\Enums\ThesisDocumentStatusEnum;
use AcademicObligations\ThesisDocuments\Models\Rules;
use AcademicObligations\ThesisDocuments\Models\ThesisDocument;

/**
 * Class ThesisDocumentSubStateService
 * @package AcademicObligations\ThesisDocuments\Services
 */
class ThesisDocumentSubStateService
{
    private $rules;

    public function __construct()
    {
        $this->rules = new Rules();
    }

    /**
     * Determina el subestado correcto para un documento según su estado actual
     * @param ThesisDocument $document
     * @return string|null
     */
    public function determineSubState(ThesisDocument $document)
    {
        $thesisStatus = $document->thesis->status;
        $documentStatus = $document->status;
        $currentSubStatus = $document->metadata->thesis_sub_status;

        $thesisRules = isset($this->rules->restrictions[$thesisStatus]) ? $this->rules->restrictions[$thesisStatus] : null;
        
        if (!$thesisRules || !isset($thesisRules['subState'])) {
            return null;
        }

        $subStates = $thesisRules['subState'];
        $subStateKeys = array_keys($subStates);

        // Si el documento está en estado PENDING, asignar el primer subestado
        if ($documentStatus === ThesisDocumentStatusEnum::PENDING) {
            return isset($subStateKeys[0]) ? $subStateKeys[0] : null;
        }

        // Si el documento está en estado APPROVED, asignar el siguiente subestado
        if ($documentStatus === ThesisDocumentStatusEnum::APPROVED) {
            $currentIndex = array_search($currentSubStatus, $subStateKeys);
            if ($currentIndex !== false && isset($subStateKeys[$currentIndex + 1])) {
                return $subStateKeys[$currentIndex + 1];
            }
        }

        // Si el documento está en estado REJECTED, mantener el mismo subestado
        if ($documentStatus === ThesisDocumentStatusEnum::REJECTED) {
            return $currentSubStatus;
        }

        // Si el documento está en estado UPLOADED, mantener el subestado actual
        if ($documentStatus === ThesisDocumentStatusEnum::UPLOADED) {
            return $currentSubStatus;
        }

        return null;
    }

    /**
     * Actualiza el subestado del documento
     * @param ThesisDocument $document
     * @return void
     */
    public function updateSubState(ThesisDocument $document)
    {
        $newSubState = $this->determineSubState($document);
        
        if ($newSubState !== null) {
            $document->metadata->thesis_sub_status = $newSubState;
            $document->metadata->save();
        }
    }

    /**
     * Obtiene las reglas aplicables para el subestado actual
     * @param ThesisDocument $document
     * @return array|null
     */
    public function getApplicableRules(ThesisDocument $document)
    {
        $thesisStatus = $document->thesis->status;
        $thesisRules = isset($this->rules->restrictions[$thesisStatus]) ? $this->rules->restrictions[$thesisStatus] : null;
        
        if (!$thesisRules) {
            return null;
        }

        $subStates = isset($thesisRules['subState']) ? $thesisRules['subState'] : null;
        
        if (!$subStates) {
            return $thesisRules;
        }

        $currentSubStatus = $document->metadata->thesis_sub_status;
        
        if ($currentSubStatus && isset($subStates[$currentSubStatus])) {
            return $subStates[$currentSubStatus];
        }

        return null;
    }

    /**
     * Verifica si el documento puede avanzar al siguiente subestado
     * @param ThesisDocument $document
     * @return bool
     */
    public function canAdvanceToNextSubState(ThesisDocument $document)
    {
        $thesisStatus = $document->thesis->status;
        $currentSubStatus = $document->metadata->thesis_sub_status;
        
        $thesisRules = isset($this->rules->restrictions[$thesisStatus]) ? $this->rules->restrictions[$thesisStatus] : null;
        
        if (!$thesisRules || !isset($thesisRules['subState'])) {
            return false;
        }

        $subStateKeys = array_keys($thesisRules['subState']);
        $currentIndex = array_search($currentSubStatus, $subStateKeys);
        
        return $currentIndex !== false && isset($subStateKeys[$currentIndex + 1]);
    }

    /**
     * Obtiene el nombre del subestado actual
     * @param ThesisDocument $document
     * @return string|null
     */
    public function getCurrentSubStateName(ThesisDocument $document)
    {
        $thesisStatus = $document->thesis->status;
        $currentSubStatus = $document->metadata->thesis_sub_status;
        
        $thesisRules = isset($this->rules->restrictions[$thesisStatus]) ? $this->rules->restrictions[$thesisStatus] : null;
        
        if (!$thesisRules || !isset($thesisRules['subState']) || !$currentSubStatus) {
            return null;
        }

        return isset($thesisRules['subState'][$currentSubStatus]['name']) ? 
               $thesisRules['subState'][$currentSubStatus]['name'] : null;
    }
} 

