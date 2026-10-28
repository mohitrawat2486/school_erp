# Responsive sidebar and workspace

Implemented the requested UI update without changing application permissions, routes or schema.

## Behavior

- Every navigation item has a consistent local SVG outline icon and visible text label.
- At widths of 1024px and above, the menu button or Collapse sidebar action slides the sidebar fully off
  the left edge. The workspace expands into the available width. The top-bar menu button reopens it.
- Desktop collapse preference is remembered in browser localStorage. Mobile open state is not persisted.
- Below 1024px, navigation opens as a left drawer above the page with a close button and dismissible backdrop.
- Escape closes the mobile drawer. Focus moves into it, stays inside while open, and returns to the trigger
  when dismissed. Background controls are inert and page scrolling is locked until dismissal.
- Breakpoint changes clear mobile modal state and scroll locking. Desktop preference remains independent.
- Sidebar navigation scrolls independently, including on short screens. Active links expose aria-current.
- Reduced-motion preferences disable the slide transition. A no-JavaScript style fallback keeps navigation available.
- The top bar truncates long school/account names while preserving full text in titles. Mobile logout stays accessible.
- Forms fit narrow widths with 16px controls, touch-friendly buttons, wrapping toolbars and constrained grid children.
- Wide tables scroll within keyboard-focusable regions instead of widening the page; text wraps at word boundaries.
- A keyboard skip link targets the main content. Sidebar/header chrome is omitted when printing.

## Files

Created:

- resources/views/components/icon.blade.php
- resources/views/components/sidebar-link.blade.php
- development/ui-navigation.md
- development/previews/sidebar-desktop.png
- development/previews/sidebar-mobile-open.png
- development/previews/sidebar-mobile-content.png

Modified:

- resources/views/components/app-layout.blade.php
- resources/css/app.css
- resources/js/app.js
- README.md

No new application packages, migrations or database configuration changes were required. Icons are code-native
SVGs. Existing Blade permission checks still determine which links appear.

## Verification

- Existing PHP regression suite: 168 tests passed, 1131 assertions.
- Production Vite build, Blade compilation, Pint, JavaScript syntax and changed-code whitespace checks passed.
- Headless Chromium checks covered desktop collapse/reopen, expanded workspace, preference persistence,
  per-link icons and active-navigation state.
- Mobile checks covered opening, modal semantics, initial focus, Tab/Shift+Tab wrapping, Escape, focus return,
  backdrop dismissal, resize cleanup, reduced motion and keyboard table scrolling.
- Six rendered screens were checked for page overflow at 320, 390, 768, 1024 and 1440 pixels:
  Dashboard, Employees, Add Employee, Classes, School Details and Users. All passed.
- Desktop and mobile screenshots were visually reviewed; mobile table word wrapping was improved after review.
- Browser fixtures were rendered using a rolled-back transaction in school_erp_testing and served only from
  an isolated temporary directory. Application data and existing user accounts were not changed.
- Browser automation used temporary tooling under /tmp, not a new project dependency. Other browsers and PDF
  layouts were not browser-tested in this update.

## Manual checks

1. Refresh an authenticated page. Every authorized sidebar link should have an icon.
2. On desktop, click Collapse sidebar or the top-bar menu button. The sidebar slides left and content expands.
3. Refresh while collapsed: it should stay collapsed. Use the top-bar menu button to reopen it.
4. At a phone/tablet width, use the menu button. Close with X, backdrop or Escape and confirm normal scrolling resumes.
5. Navigate the drawer with Tab/Shift+Tab; background controls must not receive focus while it is open.
6. Resize between phone and desktop widths while the drawer is open; there must be no leftover backdrop or scroll lock.
7. Open a long list on a phone. Swipe the table horizontally while the rest of the page remains within the viewport.
8. Check a read-only account to confirm the same permission-based navigation remains in effect.

The planned next business phase remains Students; this UI update does not advance that phase.
