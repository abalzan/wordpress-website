# Event content diff: before Stage 0.5 verification vs after the script-contract run

Files: 01-event-content-before.tsv (taken before any WordPress process was started)
      09-event-content-after-unexpected-change.tsv (taken after ./scripts/run-tests.sh --scripts)

Columns: ID, post_status, post_date_gmt, post_modified_gmt, post_name, post_title

```
# counts
before rows: 3640
after rows:  3687

# added / modified / removed (ID sets)
added id: 170878
added id: 170884
added id: 170885
added id: 170889
added id: 170894
added id: 170896
added id: 170897
added id: 170900
added id: 170902
added id: 170904
added id: 170906
added id: 170908
added id: 170910
added id: 170912
added id: 170914
added id: 170916
added id: 170918
added id: 170920
added id: 170922
added id: 170924
added id: 170926
added id: 170928
added id: 170930
added id: 170932
added id: 170934
added id: 170936
added id: 170938
added id: 170940
added id: 170942
added id: 170943
added id: 170945
added id: 170947
added id: 170949
added id: 170951
added id: 170954
added id: 170957
added id: 170959
added id: 170961
added id: 170963
added id: 170965
added id: 170967
added id: 170969
added id: 170971
added id: 170973
added id: 170975
added id: 170977
added id: 170979

# rows whose ID+status+modified tuple differs
< 19468	publish	2026-11-14 20:13:50
> 19468	publish	2026-11-14 21:33:03
< 133250	publish	2026-11-10 00:27:01
< 133254	publish	2026-11-09 23:50:35
< 133262	publish	2026-11-10 00:27:02
< 133323	publish	2026-11-09 08:17:10
< 133328	publish	2026-11-10 00:27:14
> 133250	publish	2026-11-14 21:29:15
> 133254	publish	2026-11-14 21:29:18
> 133262	publish	2026-11-14 21:28:25
> 133323	publish	2026-11-14 21:29:28
> 133328	publish	2026-11-14 21:29:28
< 134383	publish	2026-11-08 20:50:41
> 134383	publish	2026-11-14 21:28:08
< 134901	publish	2026-11-14 19:08:29
> 134901	publish	2026-11-14 21:28:03
< 134932	publish	2026-11-09 23:49:49
> 134932	publish	2026-11-14 21:28:09
< 134936	publish	2026-11-08 21:57:08
< 134939	publish	2026-11-09 23:49:50
> 134936	publish	2026-11-14 21:28:13
> 134939	publish	2026-11-14 21:28:16
< 139302	publish	2026-11-09 23:50:28
> 139302	publish	2026-11-14 21:28:08
< 139363	publish	2026-11-10 00:27:20
> 139363	publish	2026-11-14 21:28:18
> 170878	publish	2026-11-14 21:28:06
> 170884	publish	2026-11-14 21:28:11
> 170885	publish	2026-11-14 21:28:12
> 170889	publish	2026-11-14 21:28:14
> 170894	publish	2026-11-14 21:28:19
> 170896	publish	2026-11-14 21:28:22
> 170897	publish	2026-11-14 21:28:24
> 170900	publish	2026-11-14 21:28:27
> 170902	publish	2026-11-14 21:28:29
> 170904	publish	2026-11-14 21:28:30
> 170906	publish	2026-11-14 21:28:34
> 170908	publish	2026-11-14 21:28:36
> 170910	publish	2026-11-14 21:28:38
> 170912	publish	2026-11-14 21:28:40
```

## Exact numbers

| Measure | Before | After |
|---|---|---|
| `event` rows | 3640 | 3687 |
| Added | — | **47** |
| Removed | — | **0** |
| Modified (status/date/modified tuple) | — | **13** |

Modified ids: `19468`, `133250`, `133254`, `133262`, `133323`, `133328`, `134383`,
`134901`, `134932`, `134936`, `134939`, `139302`, `139363`.

Every modified row and every added row carries `_event_source = eventbrite_cork`;
the window is `2026-11-14 21:28:03` -> `21:29:28` (UTC).
