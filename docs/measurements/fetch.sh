#!/bin/bash
# Paced fetch helper: retries past LiteSpeed throttle 403s, never bursts.
UA='Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36'
url="$1"; out="${2:-/dev/null}"; hdr="${3:-/dev/null}"
for attempt in 1 2 3 4 5; do
  res=$(curl -sS -o "$out" -D "$hdr" \
    -w "%{http_code} %{time_appconnect} %{time_starttransfer} %{time_total} %{size_download}" \
    --max-time 60 --compressed -A "$UA" \
    -H 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8' \
    -H 'Accept-Language: he-IL,he;q=0.9,en-US;q=0.8' \
    "$url")
  code=${res%% *}
  if [ "$code" != "403" ]; then echo "$res"; return 0 2>/dev/null || exit 0; fi
  sleep $((attempt * 4))
done
echo "$res"
