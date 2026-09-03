<?php

namespace ProofAge\Laravel\Resources;

/**
 * The SDK resource under its pre-0.7 name. ProofAgeClient builds this class, so a type hint on
 * either name is satisfied. Methods and array shapes are the SDK's; see
 * ProofAge\Sdk\Resources\WorkspaceResource and the SDK's AGENTS.md for the contract.
 */
class WorkspaceResource extends \ProofAge\Sdk\Resources\WorkspaceResource {}
