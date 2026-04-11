<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Levee lorsque l'analyse visuelle GPT-4o echoue ou retourne un resultat inutilisable.
 */
class InspirationAnalysisException extends RuntimeException {}
