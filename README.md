# ObbyWikiHomePage

Add an ObbyWiki-specific custom home page, overwriting `Home` by default with custom HTML. Intended for use on obby.wiki. Supports MW 1.45-1.46. Minimum version is locked to 1.45.1, but the extension is mostly compatible with MW 1.43.


This extension was designed for usage on Obby Wiki server architecture and is not guaranteed to be functional elsewhere.

## Dependencies

### Soft Dependencies

The following extensions provide functionality but are not required:

* [TrendingArticles](https://github.com/wikux/mediawiki-extensions-TrendingArticles)

## TODO

Automated pulling for the highlights carousel is currently disabled because it does not work correctly.

### Functionality
* **External Links**: Add a section for community hubs (Discord, Twitter, Roblox Groups).
* **Dynamic Browse**: Move hardcoded categories to extension configuration.
* **Site Statistics**: Create a visual "At a Glance" section for wiki-wide stats. Somewhere below the area, maybe split the contributing section and put this as the other half.
* **Spotlight carousel**: Fix automatic featured-page selection (displaytitle parsing is finicky) and re-enable when stable; support rotation/diversity so the same pages are not always shown.
* Add dynamic site events like seasonal obby highlights and other events like sales in games
* "On this day..." releases (potentialy better with a template and cargo)
* **FAQ**: hardest roblox obbies, how do i find new obbies to play, best obbies for beginners, how do i make an obby, etc.
* add alt text to thumbnails
* "find obby games to play" sort/feature
* add JSON-LD (`WebSite` + `SearchAction`), `og:image`, and canonical URL for the home page

### UX
* respect `prefers-reduced-motion` for carousel autoplay; improve keyboard/focus on spotlight slides (clones are not focusable)

### Technical
* **Localization (i18n)**: Move hardcoded strings to system messages.
* **Image Optimization**: Ensure efficient thumbnail sizes are used.

### Misc
* Make the H1 'Roblox Obby Wiki' for search enginges (i.e., Googlebot, etc.)
