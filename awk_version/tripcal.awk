#!/usr/bin/awk -f
# tripcal.awk -- convert a trip file to a self-contained HTML calendar
# Usage: awk -f tripcal.awk spring2026.txt > spring2026.html
#        ./tripcal.awk spring2026.txt > spring2026.html

# ---------------------------------------------------------------------------
# UTILITY FUNCTIONS
# ---------------------------------------------------------------------------

function ltrim(s) { gsub(/^[ \t]+/, "", s); return s }
function rtrim(s) { gsub(/[ \t]+$/, "", s); return s }
function trim(s)  { return ltrim(rtrim(s)) }

function starts_with(s, prefix,    n) {
    n = length(prefix)
    return (substr(s, 1, n) == prefix)
}

function tolower_str(s,    i, c, r) {
    r = ""
    for (i = 1; i <= length(s); i++) {
        c = substr(s, i, 1)
        if (c ~ /[A-Z]/) c = sprintf("%c", ord(c) + 32)
        r = r c
    }
    return r
}

function ucwords(s,    words, n, i, w, first, rest, result) {
    n = split(s, words, " ")
    result = ""
    for (i = 1; i <= n; i++) {
        w = words[i]
        if (length(w) > 0) {
            first = substr(w, 1, 1)
            rest  = substr(w, 2)
            if (first ~ /[a-z]/) first = sprintf("%c", ord(first) - 32)
            w = first rest
        }
        result = result (i > 1 ? " " : "") w
    }
    return result
}

function ord(c) {
    return index("\001\002\003\004\005\006\007\010\011\012\013\014\015\016\017\020\021\022\023\024\025\026\027\030\031\032\033\034\035\036\037 !\"#$%&'()*+,-./0123456789:;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ[\\]^_`abcdefghijklmnopqrstuvwxyz{|}~", c)
}

# ---------------------------------------------------------------------------
# DATE MATH  (days since 2000-01-01 as epoch)
# ---------------------------------------------------------------------------

function is_leap(y) {
    return (y % 4 == 0 && y % 100 != 0) || (y % 400 == 0)
}

function days_in_month(m, y) {
    if (m == 2) return is_leap(y) ? 29 : 28
    if (m == 4 || m == 6 || m == 9 || m == 11) return 30
    return 31
}

# days from 2000-01-01 to year y, month m, day d
function to_epoch(d, m, y,    i, e) {
    e = 0
    for (i = 2000; i < y; i++) e += is_leap(i) ? 366 : 365
    for (i = 1; i < m; i++)    e += days_in_month(i, y)
    e += d - 1
    return e
}

# restore y/m/d from epoch — sets globals ep_y ep_m ep_d
function from_epoch(e,    y, m, dim) {
    y = 2000
    while (1) {
        dim = is_leap(y) ? 366 : 365
        if (e < dim) break
        e -= dim; y++
    }
    m = 1
    while (1) {
        dim = days_in_month(m, y)
        if (e < dim) break
        e -= dim; m++
    }
    ep_y = y; ep_m = m; ep_d = e + 1
}

# day of week: 0=Sun (Zeller-ish)
function dow(e,    d) {
    # 2000-01-01 was a Saturday = 6
    d = (e + 6) % 7
    return d
}

# parse DDmmmYY or DDmmmYYYY token -> epoch, return -1 on failure
function parse_date(tok,    d, mon_str, y, mon_num) {
    if (tok !~ /^[0-9]{1,2}[a-zA-Z]{3}[0-9]{2,4}$/) return -1
    match(tok, /^([0-9]{1,2})([a-zA-Z]{3})([0-9]{2,4})$/)
    d       = substr(tok, RSTART, RLENGTH)  # full match — extract pieces manually
    # re-extract via split approach
    if (match(tok, /[a-zA-Z]{3}/)) {
        mon_str = tolower_str(substr(tok, RSTART, 3))
        d       = int(substr(tok, 1, RSTART - 1))
        y       = int(substr(tok, RSTART + 3))
    } else {
        return -1
    }
    if (y < 100) y += 2000
    mon_num = MONTHS[mon_str]
    if (mon_num == "") return -1
    return to_epoch(d, mon_num, y)
}

# format epoch as DD-MON-YY
function fmt_date(e,    mo_abbr) {
    from_epoch(e)
    mo_abbr = MON_ABBR[ep_m]
    return sprintf("%02d-%s-%02d", ep_d, mo_abbr, ep_y % 100)
}

# ---------------------------------------------------------------------------
# HTML ENCODING
# ---------------------------------------------------------------------------

function html_escape(s) {
    gsub(/&/, "\\&amp;",  s)
    gsub(/</, "\\&lt;",   s)
    gsub(/>/, "\\&gt;",   s)
    gsub(/"/, "\\&quot;", s)
    return s
}

function urlencode_apple(s,    r, i, c) {
    r = ""
    for (i = 1; i <= length(s); i++) {
        c = substr(s, i, 1)
        if (c ~ /[A-Za-z0-9._~:@!$&'()*+,;=\/-]/) r = r c
        else if (c == " ")  r = r "%20"
        else if (c == ",")  r = r "%2C"
        else if (c == "#")  r = r "%23"
        else if (c == "%")  r = r "%25"
        else if (c == "?")  r = r "%3F"
        else if (c == "=")  r = r "%3D"
        else if (c == "&")  r = r "%26"
        else r = r c
    }
    return r
}

function urlencode_google(s,    r, i, c) {
    r = ""
    for (i = 1; i <= length(s); i++) {
        c = substr(s, i, 1)
        if (c == " ")      r = r "+"
        else if (c == ",") r = r "%2C"
        else               r = r c
    }
    return r
}

# render inline markup: [label](url) and [wiki](page)
function render_inline(s, epoch,    result, pre, m1, m2, rest, slug, date_str, href, page) {
    result = ""
    while (length(s) > 0) {
        # look for next [
        if (match(s, /\[/)) {
            pre = substr(s, 1, RSTART - 1)
            result = result html_escape(pre)
            s = substr(s, RSTART)
            # try wiki link: [wiki](PageName)
            if (match(s, /^\[wiki\]\(([^)]+)\)/)) {
                full = substr(s, 1, RLENGTH)
                # extract PageName between ( and )
                match(full, /\(([^)]+)\)/)
                page = substr(full, RSTART+1, RLENGTH-2)
                slug = page
                gsub(/ /, "", slug)
                slug = ucwords(slug)
                gsub(/ /, "", slug)
                from_epoch(epoch)
                date_str = sprintf("%04d-%02d-%02d", ep_y, ep_m, ep_d)
                href = "/Trips/" date_str "-" slug
                result = result "<a href=\"" html_escape(href) "\" target=\"_blank\">" html_escape(page) "</a>"
                s = substr(s, RLENGTH + 1)
            # try external link: [label](url)
            } else if (match(s, /^\[[^\]]+\]\(https?:\/\/[^)]+\)/)) {
                full = substr(s, 1, RLENGTH)
                s    = substr(s, RLENGTH + 1)
                # label: between [ and ]
                match(full, /\[[^\]]+\]/)
                m1 = substr(full, RSTART+1, RLENGTH-2)
                # url: between last ( and last )
                match(full, /\(https?:\/\/[^)]+\)/)
                m2 = substr(full, RSTART+1, RLENGTH-2)
                result = result "<a href=\"" html_escape(m2) "\" target=\"_blank\">" html_escape(m1) "</a>"
            } else {
                result = result html_escape("[")
                s = substr(s, 2)
            }
        } else {
            result = result html_escape(s)
            s = ""
        }
    }
    return result
}

# ---------------------------------------------------------------------------
# BEGIN: initialise lookup tables
# ---------------------------------------------------------------------------

BEGIN {
    MONTHS["jan"] = 1;  MONTHS["feb"] = 2;  MONTHS["mar"] = 3
    MONTHS["apr"] = 4;  MONTHS["may"] = 5;  MONTHS["jun"] = 6
    MONTHS["jul"] = 7;  MONTHS["aug"] = 8;  MONTHS["sep"] = 9
    MONTHS["oct"] = 10; MONTHS["nov"] = 11; MONTHS["dec"] = 12

    MON_ABBR[1]  = "JAN"; MON_ABBR[2]  = "FEB"; MON_ABBR[3]  = "MAR"
    MON_ABBR[4]  = "APR"; MON_ABBR[5]  = "MAY"; MON_ABBR[6]  = "JUN"
    MON_ABBR[7]  = "JUL"; MON_ABBR[8]  = "AUG"; MON_ABBR[9]  = "SEP"
    MON_ABBR[10] = "OCT"; MON_ABBR[11] = "NOV"; MON_ABBR[12] = "DEC"

    MON_FULL[1]  = "January";   MON_FULL[2]  = "February"; MON_FULL[3]  = "March"
    MON_FULL[4]  = "April";     MON_FULL[5]  = "May";      MON_FULL[6]  = "June"
    MON_FULL[7]  = "July";      MON_FULL[8]  = "August";   MON_FULL[9]  = "September"
    MON_FULL[10] = "October";   MON_FULL[11] = "November"; MON_FULL[12] = "December"

    # Color palette — edit hex values here to change location colors
    # Colors assigned in order to each new location encountered
    PAL[1]  = "#ffffd0"   # light yellow
    PAL[2]  = "#ffd0ff"   # light magenta
    PAL[3]  = "#ffd0d0"   # light red
    PAL[4]  = "#d0ffff"   # light cyan
    PAL[5]  = "#d0ffd0"   # light green
    PAL[6]  = "#d0d0ff"   # light blue
    PAL[7]  = "#ffd0b0"   # light orange
    PAL[8]  = "#f0d0ff"   # light violet
    PAL[9]  = "#d0ffe0"   # light mint
    PAL[10] = "#fff0d0"   # light peach
    PAL_COUNT = 10

    SEP = "\x01"   # activity separator (ASCII 1, won't appear in content)

    trip_title    = ""
    start_epoch   = -1
    end_epoch     = -1
    cur_epoch     = -1
    cur_location  = ""
    color_idx     = 0
    n_stops       = 0
    n_events      = 0
    n_warnings    = 0
}

# ---------------------------------------------------------------------------
# LINE PROCESSING
# ---------------------------------------------------------------------------

{
    line = $0

    # title
    if (match(line, /^title[ \t]+/)) {
        trip_title = trim(substr(line, RLENGTH + 1))
        next
    }

    # start
    if (match(line, /^start[ \t]+/)) {
        tok = trim(substr(line, RLENGTH + 1))
        e = parse_date(tok)
        if (e >= 0) start_epoch = e
        else warnings[++n_warnings] = "Line " NR ": bad date in start tag: " tok
        next
    }

    # end
    if (match(line, /^end[ \t]+/)) {
        tok = trim(substr(line, RLENGTH + 1))
        e = parse_date(tok)
        if (e >= 0) end_epoch = e
        else warnings[++n_warnings] = "Line " NR ": bad date in end tag: " tok
        next
    }

    # blank line
    if (trim(line) == "") next

    # indented activity line
    if (line ~ /^[ \t]/) {
        if (cur_epoch >= 0) {
            act = trim(line)
            if (ev_activities[cur_epoch] == "")
                ev_activities[cur_epoch] = act
            else
                ev_activities[cur_epoch] = ev_activities[cur_epoch] SEP act
        }
        next
    }

    # left-margin lines
    first = $1
    rest  = trim(substr(line, length(first) + 1))

    # + line
    if (first == "+") {
        if (cur_epoch < 0) next
        cur_epoch++
        parse_arriving_rest(rest, cur_epoch, 1)
        next
    }

    # date line
    e = parse_date(first)
    if (e >= 0) {
        cur_epoch = e
        parse_arriving_rest(rest, cur_epoch, 0)
        ev_explicit[cur_epoch] = 1
        next
    }

    # unrecognised left-margin line
    warnings[++n_warnings] = "Line " NR ": unrecognised: " trim(line)
}

# ---------------------------------------------------------------------------
# parse_arriving_rest: shared logic for + and date lines
# ---------------------------------------------------------------------------

function parse_arriving_rest(rest, epoch, is_plus,    arriving, loc, dist, parts, n) {
    arriving = 0
    loc      = ""
    dist     = ""

    if (tolower_str(substr(rest, 1, 8)) == "arriving") {
        arriving = 1
        rest = trim(substr(rest, 9))
        # split on pipe for optional distance
        if (index(rest, "|") > 0) {
            n = split(rest, parts, "|")
            loc  = trim(parts[1])
            dist = trim(parts[2])
        } else {
            loc = rest
        }
        if (loc == "") loc = cur_location
        prev_location = cur_location
        cur_location  = loc
    } else {
        # non-arriving: text is location for date lines, activity for + lines
        if (!is_plus && rest != "") cur_location = rest
        loc = cur_location
        prev_location = ""
    }

    # record event
    if (!(epoch in ev_location)) {
        ev_location[epoch]      = cur_location
        ev_arriving[epoch]      = arriving
        ev_prev_location[epoch] = arriving ? prev_location : ""
        ev_distance[epoch]      = dist
        ev_activities[epoch]    = ""
        event_epochs[++n_events] = epoch
    }

    # route stops for maps URLs
    if (arriving) {
        if (n_stops == 0 && prev_location != "") stops[++n_stops] = prev_location
        stops[++n_stops] = cur_location
    } else if (n_stops == 0 && cur_location != "") {
        stops[++n_stops] = cur_location
    }

    # non-arriving inline text on + line is an activity
    if (is_plus && !arriving && rest != "") {
        if (ev_activities[epoch] == "")
            ev_activities[epoch] = rest
        else
            ev_activities[epoch] = ev_activities[epoch] SEP rest
    }
}

# ---------------------------------------------------------------------------
# END: build calendar and emit HTML
# ---------------------------------------------------------------------------

END {

    if (trip_title == "") trip_title = FILENAME
    gsub(/\.txt$/, "", trip_title)

    # sort event epochs
    n = n_events
    for (i = 1; i <= n; i++) sorted_epochs[i] = event_epochs[i]
    # bubble sort (small n, fine)
    for (i = 1; i <= n; i++)
        for (j = i+1; j <= n; j++)
            if (sorted_epochs[j] < sorted_epochs[i]) {
                tmp = sorted_epochs[i]
                sorted_epochs[i] = sorted_epochs[j]
                sorted_epochs[j] = tmp
            }

    first_event = sorted_epochs[1]
    last_event  = sorted_epochs[n]

    # calendar start: prev Sunday of start_epoch or first event
    anchor = (start_epoch >= 0) ? start_epoch : first_event
    range_start = anchor - dow(anchor)

    # calendar end: last day of month containing end_epoch or 4 weeks past last event
    if (end_epoch >= 0) {
        anchor_end = end_epoch
    } else {
        anchor_end = last_event + 28
    }
    from_epoch(anchor_end)
    range_end = to_epoch(days_in_month(ep_m, ep_y), ep_m, ep_y)

    # assign colors to locations in encounter order
    for (i = 1; i <= n; i++) {
        e = sorted_epochs[i]
        for (li = 1; li <= 2; li++) {
            loc = (li == 1) ? ev_prev_location[e] : ev_location[e]
            if (loc != "" && !(loc in loc_color)) {
                loc_color[loc] = PAL[++color_idx % PAL_COUNT == 0 ? PAL_COUNT : color_idx % PAL_COUNT]
            }
        }
    }

    # fill all_days
    running_loc = ""
    for (e = range_start; e <= range_end; e++) {
        out_of_trip = (e < first_event) || (end_epoch >= 0 && e > end_epoch)
        if (out_of_trip) {
            all_arriving[e]      = 0
            all_location[e]      = ""
            all_prev_loc[e]      = ""
            all_distance[e]      = ""
            all_activities[e]    = ""
            all_out[e]           = 1
            all_idle[e]          = 0
        } else if (e in ev_location) {
            all_arriving[e]      = ev_arriving[e]
            all_location[e]      = ev_location[e]
            all_prev_loc[e]      = ev_prev_location[e]
            all_distance[e]      = ev_distance[e]
            all_activities[e]    = ev_activities[e]
            all_out[e]           = 0
            all_idle[e]          = 0
            running_loc          = ev_location[e]
        } else {
            all_arriving[e]      = 0
            all_location[e]      = running_loc
            all_prev_loc[e]      = ""
            all_distance[e]      = ""
            all_activities[e]    = ""
            all_out[e]           = 0
            all_idle[e]          = (running_loc != "")
        }
    }

    # build maps URLs
    apple_url  = build_apple_url()
    google_url = build_google_url()

    # emit HTML
    emit_html()
}

# ---------------------------------------------------------------------------
# URL builders
# ---------------------------------------------------------------------------

function build_apple_url(    url, i) {
    if (n_stops < 2) return ""
    url = "https://maps.apple.com/directions?mode=driving"
    url = url "&source=" urlencode_apple(stops[1])
    for (i = 2; i < n_stops; i++) {
        url = url "&waypoint=" urlencode_apple(stops[i])
        url = url "&waypoint-place-id="
    }
    url = url "&destination=" urlencode_apple(stops[n_stops])
    return url
}

function build_google_url(    url, i) {
    if (n_stops < 2) return ""
    url = "https://www.google.com/maps/dir"
    for (i = 1; i <= n_stops; i++)
        url = url "/" urlencode_google(stops[i])
    return url
}

# ---------------------------------------------------------------------------
# Output helpers — write to OUT file
# ---------------------------------------------------------------------------

function out(s)  { print s }
function outf(s) { printf "%s", s }

# ---------------------------------------------------------------------------
# HTML emission
# ---------------------------------------------------------------------------

function emit_html(    e, i, dow_val, loc, bg, style, label, acts, n_acts, act,
                       from_e, to_e, pc, nc, dist) {

    out("<!DOCTYPE html>")
    out("<html lang=\"en\">")
    out("<head>")
    out("<meta charset=\"UTF-8\">")
    out("<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">")
    out("<title>" html_escape(trip_title) "</title>")
    emit_css()
    out("</head>")
    out("<body>")

    # nav
    out("<div class=\"nav\">")
    out("  <h1>" html_escape(trip_title) "</h1>")
    out("</div>")

    # legend
    out("<div class=\"legend\">")
    for (loc in loc_color) {
        out("  <div class=\"legend-item\">")
        out("    <span class=\"swatch\" style=\"background:" loc_color[loc] "\"></span>")
        out("    " html_escape(loc))
        out("  </div>")
    }
    out("</div>")

    # warnings
    if (n_warnings > 0) {
        out("<div class=\"parse-warnings\">")
        for (i = 1; i <= n_warnings; i++)
            out("  <div class=\"parse-warning\">" html_escape(warnings[i]) "</div>")
        out("</div>")
    }

    # maps links
    if (apple_url != "" || google_url != "") {
        out("<div class=\"maps-link\">")
        if (apple_url != "")
            outf("  <a href=\"" html_escape(apple_url) "\" target=\"_blank\">open in apple maps</a>")
        if (apple_url != "" && google_url != "")
            outf(" &nbsp;&bull;&nbsp; ")
        if (google_url != "")
            outf("<a href=\"" html_escape(google_url) "\" target=\"_blank\">open in google maps</a>")
        out("")
        out("</div>")
    }

    # DOW header
    out("<div class=\"dow-row\">")
    split("Sun Mon Tue Wed Thu Fri Sat", dow_names, " ")
    for (i = 1; i <= 7; i++)
        out("  <div class=\"dow-header\">" dow_names[i] "</div>")
    out("</div>")

    # calendar grid
    out("<div class=\"cal-grid\">")

    for (e = range_start; e <= range_end; e++) {
        label  = fmt_date(e)
        is_out = all_out[e]

        if (is_out) {
            out("  <div class=\"cal-cell out-of-trip\"><span class=\"day-num\">" label "</span></div>")
            continue
        }

        loc      = all_location[e]
        arriving = all_arriving[e]
        prev_loc = all_prev_loc[e]
        dist     = all_distance[e]
        idle     = all_idle[e]

        style = ""
        cls   = "cal-cell"

        if (arriving && prev_loc != "") {
            pc    = (prev_loc in loc_color) ? loc_color[prev_loc] : "#cccccc"
            nc    = (loc in loc_color)      ? loc_color[loc]      : "#aaaaaa"
            style = "background:linear-gradient(135deg," pc " 50%," nc " 50%);"
            cls   = cls " travel"
        } else if (loc != "") {
            bg    = (loc in loc_color) ? loc_color[loc] : "#dddddd"
            style = "background:" bg ";"
            cls   = cls (idle ? " idle" : " stay")
        }

        out("  <div class=\"" cls "\" style=\"" style "\">")
        out("    <span class=\"day-num\">" label "</span>")

        # activities
        acts = all_activities[e]
        if (acts != "") {
            out("    <div class=\"act-scroll\">")
            n_acts = split(acts, act_arr, SEP)
            for (i = 1; i <= n_acts; i++) {
                act = trim(act_arr[i])
                if (act != "")
                    out("      <span class=\"act-line\">" render_inline(act, e) "</span>")
            }
            out("    </div>")
        }

        # travel footer
        if (arriving) {
            out("    <div class=\"travel-footer\">")
            if (dist != "")
                out("      <span class=\"dist-label\">" html_escape(dist) "</span>")
            out("      <span class=\"loc-label\">" html_escape(loc) "</span>")
            out("    </div>")
        }

        out("  </div>")
    }

    out("</div>")  # cal-grid
    out("</body>")
    out("</html>")
}

# ---------------------------------------------------------------------------
# CSS
# ---------------------------------------------------------------------------

function emit_css() {
out("<style>")
out("@import url('https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Playfair+Display:wght@700&display=swap');")
out(":root { --bg:#ffffff; --border:#000000; --text:#000000; --header:#eeeeee; }")
out("* { box-sizing:border-box; margin:0; padding:0; }")
out("body { background:#fff; color:#000; font-family:'DM Mono',monospace; padding:2rem; max-width:980px; margin:0 auto; }")
out("h1 { font-family:'Playfair Display',serif; font-size:2rem; margin-bottom:0.5rem; }")
out(".nav { display:flex; align-items:center; gap:1rem; margin-bottom:1.5rem; }")
out(".legend { display:flex; flex-wrap:wrap; gap:0.75rem; margin-bottom:1rem; }")
out(".legend-item { display:flex; align-items:center; gap:0.4rem; font-size:0.7rem; }")
out(".swatch { display:inline-block; width:12px; height:12px; border-radius:2px; }")
out(".maps-link { margin-bottom:1rem; font-size:0.75rem; }")
out(".maps-link a { color:#0000cc; text-decoration:underline; }")
out(".maps-link a:hover { color:#0000ff; }")
out(".parse-warnings { margin-bottom:1rem; }")
out(".parse-warning { font-size:0.75rem; color:#cc0000; border:1px solid #cc0000; padding:0.25rem 0.5rem; margin-bottom:0.25rem; }")
out(".dow-row { display:grid; grid-template-columns:repeat(7,1fr); gap:0; border-top:1px solid #000; border-left:1px solid #000; position:sticky; top:0; z-index:10; }")
out(".dow-header { background:#eee; padding:0.35rem; font-size:0.62rem; letter-spacing:0.12em; text-transform:uppercase; font-weight:bold; text-align:center; border-right:1px solid #000; border-bottom:1px solid #000; }")
out(".cal-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:0; border-top:1px solid #000; border-left:1px solid #000; }")
out(".cal-cell { background:#fff; height:110px; display:flex; flex-direction:column; padding:0.4rem 0.5rem; font-size:0.68rem; line-height:1.5; border-right:1px solid #000; border-bottom:1px solid #000; overflow:hidden; }")
out(".cal-cell.out-of-trip { background:#fff; }")
out(".day-num { display:block; font-size:0.6rem; color:#000; letter-spacing:0.03em; margin-bottom:0.2rem; font-weight:bold; }")
out(".act-scroll { flex:1; overflow-y:auto; overflow-x:hidden; min-height:0; }")
out(".act-scroll::-webkit-scrollbar { width:3px; }")
out(".act-scroll::-webkit-scrollbar-thumb { background:rgba(0,0,0,0.3); }")
out(".act-line { display:block; font-size:0.63rem; color:#000; line-height:1.45; white-space:pre-wrap; }")
out(".act-line a { color:#0000cc; text-decoration:underline; }")
out(".act-line a:hover { color:#0000ff; }")
out(".travel-footer { margin-top:auto; padding-top:0.15rem; flex-shrink:0; }")
out(".loc-label { display:block; font-size:0.68rem; font-weight:bold; color:#000; line-height:1.3; }")
out(".dist-label { display:block; font-size:0.58rem; color:#000; letter-spacing:0.05em; }")
out("@media print { * { -webkit-print-color-adjust:exact !important; print-color-adjust:exact !important; } }")
out("</style>")
}
