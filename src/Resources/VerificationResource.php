<?php

namespace ProofAge\Laravel\Resources;

/**
 * The SDK resource under its pre-0.7 name. ProofAgeClient builds this class, so a type hint on
 * either name is satisfied. Methods, array shapes and GENDER_* constants are the SDK's; see
 * ProofAge\Sdk\Resources\VerificationResource and the SDK's AGENTS.md for the contract.
 */
class VerificationResource extends \ProofAge\Sdk\Resources\VerificationResource {}
