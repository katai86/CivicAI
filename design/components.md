# Component Structure

## Core
- `AppShell` – grid layout wrapper
- `LeftPanel` – profile, rank, XP, badges, CTA
- `MapPanel` – map canvas + controls
- `RightPanel` – live feed + preview

## Map
- `MapCanvas` – Leaflet container
- `MapMarker` – category-specific animation
- `MapFilters` – chips / toggle list

## Gamification
- `XpBar` – animated progress bar
- `RankTimeline` – rank steps
- `BadgeGrid` – unlock animations
- `Leaderboard` – monthly ranks

## Mobile
- `BottomNav` – 5 items with central FAB
- `ReportSheet` – slide-up modal
- `RewardToast` – XP animation

## Intro
- `IntroOverlay` – logo fade + city glow
