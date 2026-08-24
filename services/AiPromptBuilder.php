<?php

class AiPromptBuilder
{
    /** Nyelvkód → nyelv neve (AI promptban: "Write in X"). */
    public static function languageNameForCode(string $code): string
    {
        $names = [
            'hu' => 'Hungarian',
            'en' => 'English',
            'de' => 'German',
            'fr' => 'French',
            'it' => 'Italian',
            'es' => 'Spanish',
            'sl' => 'Slovenian',
        ];
        return $names[$code] ?? 'Hungarian';
    }

    public static function reportUnderstanding(string $title, string $description, ?string $category = null, string $outputLang = 'hu'): string
    {
        $title = trim($title);
        $description = trim($description);
        $cat = $category ? ("Current user category: " . $category . "\n") : '';
        $langName = self::languageNameForCode($outputLang);
        return
            "You are helping analyse a civic issue report. ".
            "Return ONLY a compact JSON object, no prose.\n\n" .
            "Important: Write short_admin_summary and citizen_friendly_rewrite in " . $langName . ".\n\n" .
            $cat .
            "Fields:\n" .
            "- suggested_category: one of ['road','sidewalk','lighting','trash','green','traffic','idea','civil_event']\n" .
            "- suggested_subcategory: short string\n" .
            "- urgency_level: one of ['low','medium','high']\n" .
            "- short_admin_summary: max 280 chars, in " . $langName . "\n" .
            "- citizen_friendly_rewrite: short, clear, respectful rephrasing of the description, in " . $langName . "\n" .
            "- green_related_flag: true/false – is this about trees/green spaces?\n" .
            "- confidence_score: number between 0 and 1\n\n" .
            "Input report:\n" .
            "Title: " . $title . "\n" .
            "Description: " . $description . "\n\n" .
            "JSON:";
    }

    public static function govSummary(string $scopeTitle, array $stats, array $recentReports, string $outputLang = 'hu'): string
    {
        $scopeTitle = trim($scopeTitle);
        $statsJson = json_encode($stats, JSON_UNESCAPED_UNICODE);
        $recentJson = json_encode($recentReports, JSON_UNESCAPED_UNICODE);
        $langName = self::languageNameForCode($outputLang);
        return
            "You are an assistant for a municipal government dashboard. " .
            "Return ONLY a compact JSON object, no prose.\n\n" .
            "Important: Write ALL text (summary, category, action, impact, risk, mitigation) in " . $langName . ".\n\n" .
            "Goal: create an actionable summary for decision makers.\n\n" .
            "Fields:\n" .
            "- text: short summary in " . $langName . " (max 1200 chars)\n" .
            "- top_problems: array of 3 items {category, why_now} – strings in " . $langName . "\n" .
            "- quick_wins: array of 3 items {action, expected_impact} – in " . $langName . "\n" .
            "- risks: array of 3 items {risk, mitigation} – in " . $langName . "\n\n" .
            "Scope: " . $scopeTitle . "\n" .
            "Stats JSON: " . ($statsJson ?: '{}') . "\n" .
            "Recent reports JSON (may include title/description): " . ($recentJson ?: '[]') . "\n\n" .
            "JSON:";
    }

    public static function govEsg(string $scopeTitle, array $stats, array $recentReports, string $outputLang = 'hu'): string
    {
        $scopeTitle = trim($scopeTitle);
        $statsJson = json_encode($stats, JSON_UNESCAPED_UNICODE);
        $recentJson = json_encode($recentReports, JSON_UNESCAPED_UNICODE);
        $langName = self::languageNameForCode($outputLang);
        return
            "You are an assistant for a municipal ESG/sustainability briefing. " .
            "Return ONLY a compact JSON object, no prose.\n\n" .
            "Important: Write ALL text in " . $langName . ".\n\n" .
            "Fields:\n" .
            "- text: ESG-style summary in " . $langName . " (max 1400 chars)\n" .
            "- esg_metrics: array of 5 items {metric, current_signal, next_step} – in " . $langName . "\n" .
            "- citizen_engagement: array of 3 items {idea, how_to_measure} – in " . $langName . "\n\n" .
            "Scope: " . $scopeTitle . "\n" .
            "Stats JSON: " . ($statsJson ?: '{}') . "\n" .
            "Recent reports JSON: " . ($recentJson ?: '[]') . "\n\n" .
            "JSON:";
    }

    /** M2: Monthly/quarterly city maintenance report (potholes, lighting, park, drainage). */
    public static function reportMaintenance(string $scopeTitle, string $timeframeLabel, array $stats, array $recentReports, string $outputLang = 'hu'): string
    {
        $statsJson = json_encode($stats, JSON_UNESCAPED_UNICODE);
        $recentJson = json_encode($recentReports, JSON_UNESCAPED_UNICODE);
        $langName = self::languageNameForCode($outputLang);
        return
            "You are an assistant for a municipal maintenance report. " .
            "Return ONLY a compact JSON object, no prose.\n\n" .
            "Important: Write ALL text in " . $langName . ".\n\n" .
            "Fields:\n" .
            "- text: summary in " . $langName . " (max 1200 chars): main issue categories (road, lighting, park, drainage, trash), open vs resolved, trends, which areas need attention.\n" .
            "- top_categories: array of up to 5 items {category, count, trend_comment} – strings in " . $langName . "\n" .
            "- recommendations: array of 3–5 short AI suggestions for city priorities – in " . $langName . "\n\n" .
            "Scope: " . trim($scopeTitle) . ". Period: " . trim($timeframeLabel) . ".\n" .
            "Stats JSON: " . ($statsJson ?: '{}') . "\n" .
            "Recent reports sample: " . ($recentJson ?: '[]') . "\n\n" .
            "JSON:";
    }

    /** M2: Quarterly civic engagement report. */
    public static function reportEngagement(string $scopeTitle, string $timeframeLabel, array $stats, array $recentReports, string $outputLang = 'hu'): string
    {
        $statsJson = json_encode($stats, JSON_UNESCAPED_UNICODE);
        $recentJson = json_encode($recentReports, JSON_UNESCAPED_UNICODE);
        $langName = self::languageNameForCode($outputLang);
        return
            "You are an assistant for a municipal citizen engagement report. " .
            "Return ONLY a compact JSON object, no prose.\n\n" .
            "Important: Write ALL text in " . $langName . ".\n\n" .
            "Fields:\n" .
            "- text: summary in " . $langName . " (max 1200 chars): active users, new users, reports per citizen, upvotes, participation trends; whether participation is increasing.\n" .
            "- engagement_metrics: array of up to 5 items {metric, value, interpretation} – in " . $langName . "\n" .
            "- recommendations: array of 2–4 suggestions to increase citizen participation – in " . $langName . "\n\n" .
            "Scope: " . trim($scopeTitle) . ". Period: " . trim($timeframeLabel) . ".\n" .
            "Stats JSON: " . ($statsJson ?: '{}') . "\n" .
            "Recent reports sample: " . ($recentJson ?: '[]') . "\n\n" .
            "JSON:";
    }

    /** M2: Annual sustainability report. */
    public static function reportSustainability(string $scopeTitle, string $timeframeLabel, array $stats, array $recentReports, string $outputLang = 'hu'): string
    {
        $statsJson = json_encode($stats, JSON_UNESCAPED_UNICODE);
        $recentJson = json_encode($recentReports, JSON_UNESCAPED_UNICODE);
        $langName = self::languageNameForCode($outputLang);
        return
            "You are an assistant for a municipal sustainability / green report. " .
            "Return ONLY a compact JSON object, no prose.\n\n" .
            "Important: Write ALL text in " . $langName . ".\n\n" .
            "Fields:\n" .
            "- text: summary in " . $langName . " (max 1200 chars): environmental indicators (green reports, trees if in stats), citizen engagement, governance (resolution rate, response time); trends and anomalies.\n" .
            "- sustainability_highlights: array of up to 5 items {area, indicator, note} – in " . $langName . "\n" .
            "- recommendations: array of 3–5 AI suggestions – in " . $langName . "\n\n" .
            "Scope: " . trim($scopeTitle) . ". Period: " . trim($timeframeLabel) . ".\n" .
            "Stats JSON: " . ($statsJson ?: '{}') . "\n" .
            "Recent reports sample: " . ($recentJson ?: '[]') . "\n\n" .
            "JSON:";
    }

    /** M4: Citizen sentiment from report descriptions and status notes. */
    public static function sentimentAnalysis(string $scopeTitle, array $texts, string $outputLang = 'hu'): string
    {
        $scopeTitle = trim($scopeTitle);
        $langName = self::languageNameForCode($outputLang);
        $sample = array_slice($texts, 0, 80);
        $combined = implode("\n---\n", array_map(function ($t) {
            return mb_substr(is_array($t) ? ($t['text'] ?? '') : (string)$t, 0, 500);
        }, $sample));
        if (mb_strlen($combined) > 12000) {
            $combined = mb_substr($combined, 0, 12000) . "\n[... truncated]";
        }
        return
            "You are an assistant analyzing citizen feedback and reports for a municipal dashboard. " .
            "Return ONLY a compact JSON object, no prose.\n\n" .
            "Important: Write top_concerns and emerging_issues in " . $langName . ".\n\n" .
            "Based on the following citizen report descriptions and status notes, estimate overall sentiment and extract key themes.\n\n" .
            "Fields:\n" .
            "- positive_percent: number 0-100 (share of positive/satisfied tone)\n" .
            "- neutral_percent: number 0-100 (neutral or factual)\n" .
            "- negative_percent: number 0-100 (frustration, complaint, urgency); must sum to 100 with the two above\n" .
            "- top_concerns: array of 3-6 short strings (main topics: e.g. roads, lighting, waste, green areas) in " . $langName . "\n" .
            "- emerging_issues: array of 0-4 short strings (new or rising themes mentioned) in " . $langName . "\n\n" .
            "Scope: " . $scopeTitle . "\n\n" .
            "Sample texts:\n" . ($combined ?: '(no text)') . "\n\n" .
            "JSON:";
    }

    /**
     * M14: Plain-language explanation of rule-based dashboard insights (bullets only; do not invent facts).
     *
     * @param list<string> $bulletTexts
     */
    public static function govInsightsExplain(array $bulletTexts, string $outputLang = 'hu', string $scopeTitle = ''): string
    {
        $langName = self::languageNameForCode($outputLang);
        $scopeLine = trim($scopeTitle) !== '' ? ('Scope / authority: ' . trim($scopeTitle) . "\n") : '';
        $list = [];
        $i = 1;
        foreach ($bulletTexts as $t) {
            $t = trim((string)$t);
            if ($t === '') {
                continue;
            }
            if (mb_strlen($t) > 600) {
                $t = mb_substr($t, 0, 600) . '…';
            }
            $list[] = $i . '. ' . $t;
            $i++;
        }
        $block = implode("\n", $list);

        return
            "You help municipal staff understand their dashboard. " .
            "The following lines are **rule-based automated insights** (not raw citizen messages). " .
            "Return ONLY a compact JSON object, no markdown code fences.\n\n" .
            "Important: Write the value of \"text\" in " . $langName . ".\n\n" .
            "Rules:\n" .
            "- Explain what the dashboard is signalling in 2–4 short paragraphs; keep \"text\" under 900 characters.\n" .
            "- Do NOT invent statistics, locations, or issues not implied by the bullets.\n" .
            "- For warnings, suggest what to verify operationally; for info bullets, connect themes briefly.\n" .
            "- Professional, concise tone.\n\n" .
            $scopeLine .
            "Insight bullets:\n" . ($block ?: '(none)') . "\n\n" .
            "Fields: a single key \"text\" (string).\n\n" .
            'JSON:';
    }

    /**
     * Közterületi fotó → civic bejelentés javaslatok (vision).
     * $mode: ai_blip|ai_yolo|ai_sam2|ai_depth – elemzési fókusz (ugyanaz a felhő vision modell).
     */
    public static function civicImageAnalysis(string $mode = 'ai_blip', string $outputLang = 'hu'): string
    {
        $langName = self::languageNameForCode($outputLang);
        if ($mode === 'ai_yolo') {
            $focus = 'Focus on detecting discrete civic issues/objects (pothole, trash, graffiti, damaged street furniture, traffic sign, cracked sidewalk, damaged/dry tree). List objects with confidence.';
        } elseif ($mode === 'ai_sam2') {
            $focus = 'Focus on scene composition: estimate rough coverage percentages for vegetation, pavement, building, sky, water if visible.';
        } elseif ($mode === 'ai_depth') {
            $focus = 'Focus on spatial/depth cues useful for maintenance (relative distance of issues, canopy height estimate in meters if trees visible).';
        } else {
            $focus = 'Provide a balanced civic analysis: description, category, urgency, hazard, and main objects.';
        }
        return
            "You analyse a photo for a Hungarian municipal civic reporting app (CivicAI).\n" .
            "Return ONLY a compact JSON object, no markdown fences, no prose.\n\n" .
            "Important: Write description, short_title, and suggested_subcategory in " . $langName . ".\n\n" .
            "Analysis focus: " . $focus . "\n\n" .
            "Detect if relevant: road pothole, sidewalk damage, illegal trash/dumping, graffiti, damaged street furniture, dry/diseased/damaged tree or plants, traffic sign issues, lighting, general environmental condition.\n\n" .
            "Fields:\n" .
            "- description: 1–3 sentences what is visible, in " . $langName . "\n" .
            "- short_title: max 80 chars suitable as report title, in " . $langName . "\n" .
            "- suggested_category: one of ['road','sidewalk','lighting','trash','green','traffic','idea','civil_event']\n" .
            "- suggested_subcategory: short string in " . $langName . "\n" .
            "- urgency_level: one of ['low','medium','high']\n" .
            "- hazard_level: one of ['none','low','medium','high']\n" .
            "- confidence_score: number 0–1\n" .
            "- objects: array of up to 8 items {class, confidence} – class in English snake_case (pothole, trash, graffiti, tree, sidewalk_crack, street_furniture, traffic_sign, lighting, other)\n" .
            "- segments: array of up to 6 items {kind, coverage_pct} – kind in English (vegetation, pavement, building, sky, water); coverage_pct 0–100; omit if unknown\n" .
            "- depth_notes: optional short string in " . $langName . " (only if depth/spatial focus)\n\n" .
            "JSON:";
    }

    public static function civicImageSystemPrompt(): string
    {
        return 'You are a civic infrastructure vision analyst. Reply with a JSON object only. Never invent text outside JSON. Be conservative with confidence when the image is unclear.';
    }

    /** City Brain WOW demo – utca + zöld + fa egy képen. */
    public static function citybrainVisionAnalysis(string $outputLang = 'hu'): string
    {
        $langName = self::languageNameForCode($outputLang);
        return
            "You analyse a street / public-space photo for CivicAI City Brain (municipal demo).\n" .
            "Return ONLY one JSON object, no markdown.\n\n" .
            "Write scene_summary, street_issues, wow_highlights, recommended_action, tree entries (species, health_note) in " . $langName . ".\n\n" .
            "Fields:\n" .
            "- scene_summary: 2–4 sentences describing the scene in " . $langName . "\n" .
            "- street_condition: one of ['excellent','good','fair','poor']\n" .
            "- street_issues: array of up to 6 short strings in " . $langName . " (potholes, cracks, missing signs, etc.)\n" .
            "- green_surfaces: {vegetation_pct,pavement_pct,building_pct,sky_pct,water_pct} each 0–100 (estimate)\n" .
            "- trees: array up to 5 items {species, health: healthy|dry|disease_suspected|unknown, trunk_diameter_cm, canopy_diameter_m, confidence 0–1, health_note short string in " . $langName . "}\n" .
            "- objects: array up to 10 {class, confidence} – pothole, trash, graffiti, tree, sidewalk_crack, street_furniture, traffic_sign, lighting, vegetation, other\n" .
            "- suggested_category: road|sidewalk|lighting|trash|green|traffic|idea|civil_event\n" .
            "- urgency_level: low|medium|high\n" .
            "- hazard_level: none|low|medium|high\n" .
            "- confidence_score: 0–1 overall\n" .
            "- wow_highlights: array of 3–5 impressive one-liners for a live demo in " . $langName . "\n" .
            "- recommended_action: one sentence maintenance suggestion in " . $langName . "\n\n" .
            "JSON:";
    }

    public static function citybrainVisionSystemPrompt(): string
    {
        return 'You are City Brain vision AI for smart cities. Analyse street photos holistically: pavement, greenery, trees (species, health, size), hazards. JSON only.';
    }
}

