<?php

class AiPromptBuilder
{
    public static function reportUnderstanding(string $title, string $description, ?string $category = null): string
    {
        $title = trim($title);
        $description = trim($description);
        $cat = $category ? ("Current user category: " . $category . "\n") : '';
        return
            "You are helping analyse a civic issue report. ".
            "Return ONLY a compact JSON object, no prose.\n\n" .
            $cat .
            "Fields:\n" .
            "- suggested_category: one of ['road','sidewalk','lighting','trash','green','traffic','idea','civil_event']\n" .
            "- suggested_subcategory: short string\n" .
            "- urgency_level: one of ['low','medium','high']\n" .
            "- short_admin_summary: max 280 chars, Hungarian if input is Hungarian\n" .
            "- citizen_friendly_rewrite: short, clear, respectful rephrasing of the description\n" .
            "- green_related_flag: true/false – is this about trees/green spaces?\n" .
            "- confidence_score: number between 0 and 1\n\n" .
            "Input report:\n" .
            "Title: " . $title . "\n" .
            "Description: " . $description . "\n\n" .
            "JSON:";
    }

    public static function govSummary(string $scopeTitle, array $stats, array $recentReports): string
    {
        $scopeTitle = trim($scopeTitle);
        $statsJson = json_encode($stats, JSON_UNESCAPED_UNICODE);
        $recentJson = json_encode($recentReports, JSON_UNESCAPED_UNICODE);
        return
            "You are an assistant for a Hungarian municipal government dashboard. " .
            "Return ONLY a compact JSON object, no prose.\n\n" .
            "Goal: create an actionable summary for decision makers.\n\n" .
            "Fields:\n" .
            "- text: short Hungarian summary (max 1200 chars)\n" .
            "- top_problems: array of 3 items {category, why_now}\n" .
            "- quick_wins: array of 3 items {action, expected_impact}\n" .
            "- risks: array of 3 items {risk, mitigation}\n\n" .
            "Scope: " . $scopeTitle . "\n" .
            "Stats JSON: " . ($statsJson ?: '{}') . "\n" .
            "Recent reports JSON (may include title/description): " . ($recentJson ?: '[]') . "\n\n" .
            "JSON:";
    }

    public static function govEsg(string $scopeTitle, array $stats, array $recentReports): string
    {
        $scopeTitle = trim($scopeTitle);
        $statsJson = json_encode($stats, JSON_UNESCAPED_UNICODE);
        $recentJson = json_encode($recentReports, JSON_UNESCAPED_UNICODE);
        return
            "You are an assistant for a Hungarian municipal ESG/sustainability briefing. " .
            "Return ONLY a compact JSON object, no prose.\n\n" .
            "Fields:\n" .
            "- text: Hungarian ESG-style summary (max 1400 chars)\n" .
            "- esg_metrics: array of 5 items {metric, current_signal, next_step}\n" .
            "- citizen_engagement: array of 3 items {idea, how_to_measure}\n\n" .
            "Scope: " . $scopeTitle . "\n" .
            "Stats JSON: " . ($statsJson ?: '{}') . "\n" .
            "Recent reports JSON: " . ($recentJson ?: '[]') . "\n\n" .
            "JSON:";
    }
}

