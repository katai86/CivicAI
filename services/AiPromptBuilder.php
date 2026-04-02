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
}

