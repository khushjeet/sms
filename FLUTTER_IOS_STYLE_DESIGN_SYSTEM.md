# Flutter iOS-Style Design System

## Purpose

This document defines the design-system direction for the Flutter teacher app with an iOS-style visual language that remains durable, scalable, and easy to extend across many future features.

## Design Goals

- calm and premium
- readable in dense school workflows
- touch-friendly
- consistent across 30 to 70+ future features
- easy to theme without rewriting screens

## Visual Direction

The app should feel iOS-inspired, not like a direct clone of Apple system apps.

Principles:

- soft surfaces
- generous spacing
- large-title hierarchy where useful
- clear content grouping
- restrained color usage
- smooth but subtle motion

## Design Token Model

All style values must come from tokens.

Token groups:

- color
- typography
- spacing
- radius
- border
- elevation
- opacity
- motion
- icon sizing

Never hardcode visual values in feature widgets unless there is a documented exception.

## Color System

Recommended structure:

- `primary`
- `secondary`
- `accent`
- `surface`
- `surfaceMuted`
- `background`
- `textPrimary`
- `textSecondary`
- `success`
- `warning`
- `danger`
- `info`
- `divider`

Guidance:

- keep contrast high for text-heavy educational workflows
- use color for meaning, not decoration
- attendance and marks statuses should remain distinguishable in light and dark conditions if dark mode is introduced later

## Typography

Recommended hierarchy:

- display
- title
- section title
- body
- body secondary
- caption
- data emphasis

Rules:

- use a clear type ramp
- avoid many near-identical sizes
- long tables and sheets must prioritize readability over visual novelty

## Spacing Scale

Use a small stable spacing scale, for example:

- 4
- 8
- 12
- 16
- 20
- 24
- 32

Rules:

- use spacing tokens only
- form rows, section cards, and list items must stay visually rhythmic

## Radius and Surfaces

Recommended:

- moderate rounded corners
- cards distinct from page background
- bottom sheets with clear elevation and safe-area support

Rules:

- do not vary radius arbitrarily by feature
- use one radius scale for chips, inputs, cards, and modal surfaces

## Navigation Patterns

Recommended iOS-style patterns:

- large-title top bars for major pages
- segmented control for compact mode switching
- bottom navigation only if app scope grows
- drill-down navigation for details
- sheet presentation for quick actions

## Reusable Component Library

The design system should define reusable primitives for:

- app scaffold
- top navigation bar
- section header
- primary button
- secondary button
- destructive button
- text field
- date picker trigger
- search field
- segmented control
- card container
- list row
- empty state
- loading state
- error state
- status badge
- toast/snackbar/banner
- confirmation dialog

## Teacher Workflow Components

Teacher-specific reusable UI pieces should be built on design-system primitives:

- assignment card
- timetable slot card
- attendance status picker row
- marks entry row
- notification row
- profile summary header

These should still live outside the global design tokens if they are workflow-specific.

## Status Language

Statuses must remain visually and semantically stable.

Examples:

- attendance: present, absent, leave, half day, not marked
- request state: loading, saved, failed, locked
- notification state: unread, read

Rules:

- every status uses text plus color, not color alone
- badges and labels must be reusable and token-driven

## Motion

Motion should be subtle and meaningful.

Use motion for:

- page transitions
- loading placeholders
- sheet presentation
- success feedback

Avoid:

- decorative animation loops
- heavy motion in data entry screens

## Forms

Teacher workflows are form-heavy, so form consistency matters.

Rules:

- one clear primary action per screen
- inline validation where useful
- numeric entry optimized for marks
- sticky save action for long lists if needed
- locked attendance rows visually distinct and non-editable

## Lists and Dense Data

Because school workflows often become data-dense:

- keep row layouts stable
- use alignment consistently for names, roll numbers, and marks
- support empty, partial, and long-content states
- avoid layout jumps caused by inconsistent heights

## Responsive Rules

The app should work across:

- small phones
- tall phones
- tablets if added later

Rules:

- keep adaptive width behavior in reusable layout primitives
- avoid hardcoded one-device assumptions
- do not let iOS-style visuals break Android usability

## Accessibility

Required principles:

- readable text contrast
- touch targets large enough for classroom usage
- semantic labels for controls
- screen-reader-friendly navigation labels
- visible focus and validation states

## Theming Strategy

Support future theming without breaking screens by:

- exposing tokens through theme extensions
- separating semantic colors from raw palette values
- keeping typography and spacing centralized

## Growth Rules

As the app adds many new features:

- new screens must consume existing tokens before inventing new ones
- new primitives require documentation
- design exceptions must be intentional and reviewed
- workflow-specific widgets should not fork the base design system unless necessary

## Deliverables for Implementation

The Flutter team should create:

- token definitions
- theme extensions
- shared primitive widgets
- preview/demo screens for components
- design usage notes for feature teams
