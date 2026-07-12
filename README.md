# ObbyWikiHomePage

Add an ObbyWiki-specific custom home page, overwriting `Home` by default with custom HTML. Intended for use on obbywiki.com. Supports MW 1.45-1.46. Minimum version is locked to 1.45.1, but the extension is mostly compatible with MW 1.43.

Automated pulling for the highlights carousel is currently disabled because it does not work correctly.

## TODO

### Functionality
* **External Links**: Add a section for community hubs (Discord, Twitter, Roblox Groups).
* **Dynamic Browse**: Move hardcoded categories to extension configuration.
* **Site Statistics**: Create a visual "At a Glance" section for wiki-wide stats. Somewhere below the area, maybe split the contributing section and put this as the other half.
* **Trending Section**: Feature pages with high recent growth or view counts. (NEEDS EXTENSION SUPPORT FIRST)
* **Spotlight carousel**: Fix automatic featured-page selection (displaytitle parsing is finicky) and re-enable when stable; support rotation/diversity so the same pages are not always shown.
* Add dynamic site events like seasonal obby highlights and other events like sales in games
* "On this day..." releases (potentialy better with a template and cargo)
* Cache poorly updates $wgObbyWikiHomePageFeaturedPages on featured carousel, maybe use a job queue or something to update it every 10 minutes?
* Remove or replace the user-defined edit summary/blurb/description in each recent change card
* **FAQ**: hardest roblox obbies, how do i find new obbies to play, best obbies for beginners, how do i make an obby, etc.
* add alt text to thumbnails
* "find obby games to play" sort/feature
* add JSON-LD (`WebSite` + `SearchAction`), `og:image`, and canonical URL for the home page
* first image on the home page should be fetchpriority=high, not loading=lazy

### UX
* **Mobile Layout**: Refine stacking for "Spotlight" and "Archive" sections on small screens.
  * Additionally, probably just refine the entire layout
* **Dark Mode**: Improve dark mode styling
* respect `prefers-reduced-motion` for carousel autoplay; improve keyboard/focus on spotlight slides (clones are not focusable)
* move remaining inline styles (announcements stub, etc.) into the stylesheet

### Technical
* **Localization (i18n)**: Move hardcoded strings to system messages.
* **Granular Caching**: Optimize cache TTL for different sections.
** caching needs improvements in general, like deferring the cache reset a background task and using the old cache until its ready, so users don't have to wait on cache refreshes
* **Image Optimization**: Ensure efficient thumbnail sizes are used.

### Misc
* Make the H1 'Roblox Obby Wiki' for search enginges (i.e., Googlebot, etc.)
* Replace deprecated usage of wfParseUrl
* Move the announcements section downwards
