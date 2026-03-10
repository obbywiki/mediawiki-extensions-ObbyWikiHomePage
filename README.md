# ObbyWikiHomePage

Add an ObbyWiki-specific custom home page, overwriting `Home` by default with custom HTML. Intended for use on obbywiki.com. Supports MW 1.45-1.46.

## TODO

### Functionality
* **External Links**: Add a section for community hubs (Discord, Twitter, Roblox Groups).
* **Dynamic Browse**: Move hardcoded categories to extension configuration.
* **Site Statistics**: Create a visual "At a Glance" section for wiki-wide stats. Somewhere below the area, maybe split the contributing section and put this as the other half.
* **Trending Section**: Feature pages with high recent growth or view counts. (NEEDS EXTENSION SUPPORT FIRST)
* Add dynamic site events like seasonal obby highlights and other events like sales in games
* "On this day..." releases (potentialy better with a template and cargo)
* Cache poorly updates $wgObbyWikiHomePageFeaturedPages on featured carousel, maybe use a job queue or something to update it every 10 minutes?

### UX
* **Mobile Layout**: Refine stacking for "Spotlight" and "Archive" sections on small screens.
* **Dark Mode**: Improve dark mode styling
* Override styling for some links styling

### Technical
* **Localization (i18n)**: Move hardcoded strings to system messages.
* **Granular Caching**: Optimize cache TTL for different sections.
* **Image Optimization**: Ensure efficient thumbnail sizes are used.
