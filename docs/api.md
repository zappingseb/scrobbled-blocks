# Last.fm API Reference

Working notes on the Last.fm endpoint this plugin consumes, and on the REST endpoint it exposes to the
block editor. Every request and response below was captured from a live call; API keys are redacted and
long track lists trimmed, but the field shapes are verbatim.

For the behaviour these shapes drive, see `includes/class-api.php`.

---

## 1. The upstream request

The plugin calls exactly one Last.fm method, `user.getRecentTracks`. Both blocks are fed from it — the
Now Playing block is `get_recent_tracks( 1, 1 )` returning element `[0]`.

**Base URL:** `https://ws.audioscrobbler.com/2.0/`
**Authentication:** API key only. No user authentication, no shared secret, no signing — the plugin reads
only public scrobble data.

```bash
curl -s 'https://ws.audioscrobbler.com/2.0/?method=user.getRecentTracks&user=YOUR_USERNAME&api_key=YOUR_API_KEY&format=json&limit=5'
```

| Parameter | Value | Notes |
|---|---|---|
| `method` | `user.getRecentTracks` | |
| `user` | the configured username | `Scrobbled_Blocks_Settings::get_username()` |
| `api_key` | the configured key | `Scrobbled_Blocks_Settings::get_api_key()` |
| `format` | `json` | without it Last.fm returns XML |
| `limit` | 1-20 | the block's `numberOfItems` |

Built in `Scrobbled_Blocks_API::make_request()` via `add_query_arg()`, sent with `wp_remote_get()` on a
15-second timeout.

The username and key both come from the settings screen:

![The Scrobbled Blocks settings screen, with Last.fm username, API key and default artwork placeholder fields](screenshots/settings.png)

---

## 2. The response

```json
{
  "recenttracks": {
    "@attr": {
      "user": "YOUR_USERNAME",
      "totalPages": "161295",
      "page": "1",
      "perPage": "2",
      "total": "322589"
    },
    "track": [ ... ]
  }
}
```

The plugin ignores `@attr` entirely — there is no pagination, each render is a fresh top-of-list read.

One track object, verbatim. This is the second scrobble in the Recently Played screenshot further down,
so the payload and the rendered row below are the same data:

```json
{
  "artist": {
    "mbid": "ab2528d9-719f-4261-8098-21849222a0f2",
    "#text": "Stromae"
  },
  "streamable": "0",
  "image": [
    { "size": "small",      "#text": "https://lastfm-img.freetls.fastly.net/i/u/34s/8bbbc22e83bb40f1c07956ec8e8f578e.png" },
    { "size": "medium",     "#text": "https://lastfm-img.freetls.fastly.net/i/u/64s/8bbbc22e83bb40f1c07956ec8e8f578e.png" },
    { "size": "large",      "#text": "https://lastfm-img.freetls.fastly.net/i/u/174s/8bbbc22e83bb40f1c07956ec8e8f578e.png" },
    { "size": "extralarge", "#text": "https://lastfm-img.freetls.fastly.net/i/u/300x300/8bbbc22e83bb40f1c07956ec8e8f578e.png" }
  ],
  "mbid": "7dba4726-79be-405a-a6da-150160d9a8e0",
  "album": {
    "mbid": "348662a8-54ce-4d14-adf5-3ce2cefd57bb",
    "#text": "Racine carrée"
  },
  "name": "Ta fête",
  "url": "https://www.last.fm/music/Stromae/_/Ta+f%C3%AAte",
  "date": {
    "uts": "1789550487",
    "#text": "16 Sep 2026, 09:21"
  }
}
```

Five of those, rendered by the Recently Played block in its list layout. `Ta fête` is the second row;
`name` becomes the link, `artist['#text']` the line under it, and `date.uts` the relative time:

![Recently Played block as a list of five tracks, each with album artwork, title, artist and relative time](screenshots/recently-played-list.png)

Mapped by `parse_tracks()` to:

| Plugin field | Source | Notes |
|---|---|---|
| `name` | `name` | |
| `artist` | `artist['#text']` | the `mbid` sibling is unused |
| `album` | `album['#text']` | parsed and stored, but currently only used in the `<img alt>` |
| `url` | `url` | |
| `timestamp` | `date.uts` | cast to `int`; **absent on a now-playing track** |
| `nowplaying` | `@attr.nowplaying === 'true'` | a string, not a boolean |
| `artwork` | `image[]` | see below |

### Two track shapes

A track object arrives in one of two forms, and the difference is the whole basis of the Now Playing block:

| | Scrobbled track | Now-playing track |
|---|---|---|
| `date` | present, with `uts` | **absent** |
| `@attr` | absent | `{ "nowplaying": "true" }` |

A now-playing track is returned *in addition to* `limit`, so a `limit=5` request can return six tracks.
`parse_tracks()` slices back to `limit` after parsing.

Last.fm only reports a now-playing track while a scrobbling client is actively streaming; it disappears
seconds after playback stops, which is why the block falls back to "most recently played".

### Single-track responses

When only one track matches, Last.fm collapses `track` from an array to a bare object. `parse_tracks()`
re-wraps it:

```php
if ( isset( $raw_tracks['name'] ) ) {
    $raw_tracks = array( $raw_tracks );
}
```

---

## 3. Artwork

`get_artwork_url()` walks the `image` array preferring `extralarge` (300x300), then `large` (174px), then
any non-empty entry, then the configured placeholder.

**Missing artwork has two different representations**, which is worth knowing because only one of them
triggers the plugin's placeholder fallback:

| Case | `#text` for `extralarge` | Falls back to plugin placeholder? |
|---|---|---|
| Artwork present | a real image URL | n/a |
| Last.fm's own star placeholder | `https://lastfm-img.freetls.fastly.net/i/u/300x300/2a96cbd8b46e442fc41c2b86b821562f.png` | **No** — the string is non-empty |
| No image at all | `""` | Yes |

That star-placeholder hash, `2a96cbd8b46e442fc41c2b86b821562f`, is a constant Last.fm serves for any
release it has no art for. Because it is a valid non-empty URL, an `! empty()` check accepts it as real
artwork, and a configured default never gets its turn.

Here is a real track that hits this path. Note the hash repeated across all four sizes, and that the
album name is present — this is not a track missing metadata, Last.fm simply has no cover for it:

```json
{
  "artist": { "mbid": "", "#text": "Paul Kalkbrenner, Stromae" },
  "image": [
    { "size": "small",      "#text": ".../i/u/34s/2a96cbd8b46e442fc41c2b86b821562f.png" },
    { "size": "medium",     "#text": ".../i/u/64s/2a96cbd8b46e442fc41c2b86b821562f.png" },
    { "size": "large",      "#text": ".../i/u/174s/2a96cbd8b46e442fc41c2b86b821562f.png" },
    { "size": "extralarge", "#text": ".../i/u/300x300/2a96cbd8b46e442fc41c2b86b821562f.png" }
  ],
  "album": { "mbid": "", "#text": "QUE CE SOIT CLAIR" },
  "name": "QUE CE SOIT CLAIR",
  "url": "https://www.last.fm/music/Paul+Kalkbrenner,+Stromae/_/QUE+CE+SOIT+CLAIR",
  "date": { "uts": "1789550663", "#text": "16 Sep 2026, 09:24" }
}
```

That is the first tile below. With the hash recognised as a placeholder, the block falls through to the
site's default artwork instead of showing Last.fm's grey star; every other tile has a real cover:

![Recently Played block as a three column grid of album artwork, where the first tile shows a default placeholder reading No artwork available](screenshots/recently-played-grid.jpg)

It is not a rare edge case. Across a sample of 200 consecutive scrobbles from one account:

| | Count |
|---|---|
| Real artwork | 135 |
| Last.fm star placeholder | 51 |
| Empty string | 14 |

So roughly a quarter of tracks render Last.fm's grey star rather than the site's chosen placeholder.

`user.getTopAlbums` behaves differently again — it returns an empty string rather than the star URL for
albums with no art, so the same missing-art condition needs testing on both endpoints.

---

## 4. Errors

Last.fm signals failure with **HTTP 200** and an error object in the body, so the status code alone is not
a reliable success check. `make_request()` checks the status, then callers check for an `error` key.

Invalid API key:

```json
{"message":"Invalid API key - You must be granted a valid key by last.fm","error":10}
```

Unknown username:

```json
{"message":"User not found","error":6}
```

Codes mapped to messages in `Scrobbled_Blocks_API::get_error_message()`:

| Code | Meaning |
|---|---|
| 2 | Invalid service |
| 3 | Invalid method |
| 4 | Authentication failed — check the API key |
| 5 | Invalid format |
| 6 | Invalid parameters — usually a bad username |
| 7 | Invalid resource |
| 8 | Operation failed |
| 9 | Invalid session key |
| 10 | Invalid API key |
| 11 | Service temporarily offline |
| 13 | Invalid method signature |
| 16 | Temporary error |
| 26 | API key suspended |
| 29 | Rate limit exceeded |

Codes 2, 3, 5, 7 and 13 should not occur with a correctly built request. An unmapped code falls through to
Last.fm's own `message`.

### Degradation

On any failure the blocks render **nothing** rather than an error — `render.php` returns `''`. Before
giving up, `get_recent_tracks()` tries a 24-hour stale cache (`..._stale`), so a Last.fm outage leaves the
last good data on the page for up to a day.

---

## 5. Caching

Responses go in the Transients API.

| | |
|---|---|
| Key | `scrobbled_blocks_recent_` + `md5( username . '_' . limit )` |
| Now Playing | 1 minute |
| Recently Played | 5 minutes |
| Stale fallback | 24 hours, key suffixed `_stale` |

Every key begins `scrobbled_blocks_`, which is what lets the activation and deactivation hooks in
`scrobbled-blocks.php` sweep them with a `LIKE '_transient_scrobbled_blocks_%'` query.

Last.fm's API terms ask clients to keep request rates modest per originating IP. The cache durations
above mean a normal site makes at most a few calls per minute regardless of traffic.

---

## 6. The plugin's own REST endpoint

Exposed purely so the block editor can preview live data — the front end is server-rendered and never
calls it. This is the only thing that consumes it:

![The Now Playing block selected in the WordPress editor, showing live track data and a Display Settings panel with toggles for artwork, timestamp and Last.fm link](screenshots/now-playing-editor.png)

```
GET /wp-json/scrobble-blocks/v1/recent-tracks?limit=5
```

Note the namespace is `scrobble-blocks` (no "d"), while the text domain and PHP prefix are
`scrobbled-blocks`.

**Permission:** `current_user_can( 'edit_posts' )`.

**Parameters:** `limit` — integer, default 5, min 1, max 20, `absint`.

Success:

```json
{
  "success": true,
  "tracks": [
    {
      "name": "Ta fête",
      "artist": "Stromae",
      "album": "Racine carrée",
      "url": "https://www.last.fm/music/Stromae/_/Ta+f%C3%AAte",
      "timestamp": 1789550487,
      "nowplaying": false,
      "artwork": "https://lastfm-img.freetls.fastly.net/i/u/300x300/8bbbc22e83bb40f1c07956ec8e8f578e.png"
    }
  ]
}
```

Failure:

```json
{"success": false, "error": "Invalid API key. Please check your API key."}
```

**Failures are returned with HTTP 200, not an error status.** This is deliberate: `edit.js` branches on
`response.success` and shows the message in a `Notice`, so a non-2xx would be rejected by `apiFetch()`
before that code ran.
