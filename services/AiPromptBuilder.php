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
}

