# Neutral Material Design 3

The shared CRM follows Material Design 3 conventions with an exclusively achromatic palette. Alpha and Beta retain separate identities, terminology and optional modules while using charcoal/grey primary tones. No green, purple or chromatic status/error accents are part of this theme. Administrator settings offer neutral tones; server validation rejects non-grey RGB values.

The implementation lives in `packages/awove/crm-core/resources/css/core.css`. It defines Material color roles for surface containers, on-surface, primary, secondary, outline and their corresponding foregrounds; the standard 4/8/12/16/28/full corner scale; Roboto type styles; a 4px spacing grid; and short standard motion with reduced-motion support.

Shared controls use 40px filled/outlined pill buttons (48px on compact touch layouts), 56px outlined text/select fields with floating labels, 32px chips, 56px drawer destinations, 64px top app bar, 12px outlined cards, neutral selected containers, visible focus indicators and explicit error text. Login/account security controls use the same typography, neutral palette and button geometry. Navigation uses locally bundled Material Symbols; fonts require no external font service.

Official `@material/web` ripple components supply hover/pressed state layers on native semantic controls and Inertia links. The integration reattaches after Vue updates and keeps ripple bounds positioned on each control. Native form behavior, keyboard access and existing server permissions remain in place. This is a Vue implementation of Material 3 using official Material Web interaction primitives; it does not replace Laravel/Inertia with an unrelated UI application.

References: [Material 3 typography](https://m3.material.io/styles/typography/type-scale-tokens), [navigation drawer](https://m3.material.io/components/navigation-drawer/specs), [Material Web ripple](https://github.com/material-components/material-web/blob/main/docs/components/ripple.md), [official Material 3 type and shape scales](https://developer.android.com/develop/ui/compose/designsystems/material3).

Verification: Vue/TypeScript, production frontend build, formatting, PHPStan, PostgreSQL backend tests and desktop/mobile CRM/extension browser scenarios. Updated screenshots are in `docs/screenshots/`. Visual inspection corrected a missing icon-font family and a ripple-containment issue before publication. The earlier container-image smoke in `checkpoint-1.md` predates this theme; no new container runtime acceptance is claimed for this visual iteration.
