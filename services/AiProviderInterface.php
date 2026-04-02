<?php
/**
 * AI provider interfész – Mistral / Gemini wrapper.
 */

interface AiProviderInterface {
    /**
     * Szöveges / JSON választ adó hívás.
     *
     * @param string $model   Modell neve (pl. mistral-small-2506)
     * @param string $prompt  Prompt vagy system+user üzenetekből épített string
     * @param array  $options timeout, temperature stb.
     *
     * @return array [ 'ok' => bool, 'model' => string, 'raw' => mixed ]
     */
    public function complete(string $model, string $prompt, array $options = []): array;
}

