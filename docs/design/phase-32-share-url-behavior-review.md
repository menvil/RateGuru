# Phase 32 Share & URL Behavior Review

- canonical URL helper returns absolute URL;
- copy button works through Alpine Clipboard API with fallback markup;
- copied state is rendered;
- PostDrawer share panel checked;
- PostShow share panel checked;
- og:image is absolute and has a static placeholder fallback;
- og:title is escaped through Blade output;
- og:description is stripped, truncated, and escaped through Blade output;
- no social SDKs or external share scripts added;
- no ranking, feed sorting, or hot score changes added.
