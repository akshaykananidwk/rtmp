# સંપૂર્ણ Setup Guide (ગુજરાતી) — AK COMPUTER · ONE LIVE EVERYWHERE

આ guide શરૂઆતથી અંત સુધી, એક-એક step માં લખેલી છે. ઉપરથી નીચે ક્રમમાં જ કરો.
દરેક step પછી લખેલું "ચકાસો" જરૂર કરો — ખોટું હોય તો આગળ ન વધો.

---

## ભાગ 0 — અત્યારે શું થઈ ગયું છે

✅ Application install થઈ ગઈ · ✅ Dashboard ચાલે છે · ✅ Database, Cache, Storage, SSL બરાબર

હજી બાકી છે:
- ❌ **Streaming Engine** (MediaMTX) — install નથી થયું
- ❌ **RTMP port 1935** — બંધ છે (MediaMTX ચાલુ થશે એટલે ખૂલશે)
- ⚠️ Redis (optional), GitHub auto-update (optional)

**સાદી ભાષામાં:** અત્યારે તમારી પાસે "office" (dashboard) તૈયાર છે, પણ "કેમેરાનો દરવાજો"
(RTMP server) હજી બન્યો નથી. એટલે OBS હજી જોડાઈ નહીં શકે. ભાગ 1 એ જ બનાવે છે.

---

## ભાગ 1 — Streaming Engine install કરો (સૌથી જરૂરી)

> આ માટે **VPS + root (SSH/Terminal) access** જોઈએ. Shared hosting પર RTMP ચાલતું નથી.
> aaPanel વાપરો છો તો: ડાબી બાજુ **Terminal** પર click કરો.

### Step 1.1 — નવો code લો

```bash
cd /www/wwwroot/rtmp.akdwk.in
git pull origin claude/ak-computer-streaming-saas-868j8s
```

### Step 1.2 — એક જ command થી engine install કરો

```bash
sudo bash scripts/install-mediamtx.sh /www/wwwroot/rtmp.akdwk.in https://rtmp.akdwk.in
```

આ command આપોઆપ કરે છે: FFmpeg install → MediaMTX install → `.env` માં settings લખે →
MediaMTX ને તમારી app સાથે જોડે → 3 services ચાલુ કરે → firewall માં port 1935 ખોલે → cron ગોઠવે.

છેલ્લે આવું દેખાવું જોઈએ:

```
mediamtx            : running
akstream-supervisor : running
akstream-queue      : running
```

### Step 1.2b — સર્વર 500 આપે તો (ownership)

Script root તરીકે ચાલ્યું હોય તો files ની માલિકી બદલાઈ શકે છે અને site 500 આપે.
એક command થી સરખું થઈ જશે:

```bash
sudo bash scripts/fix-permissions.sh /www/wwwroot/rtmp.akdwk.in
```

### Step 1.3 — aaPanel નું open_basedir ખોલો

> ⚠️ **આ terminal નો command નથી** — આ aaPanel ના PHP settings માં લખવાનું છે.
> Terminal માં paste કરશો તો "No such file or directory" આવશે.

aaPanel PHP ને ફક્ત website folder સુધી જ જોવા દે છે, એટલે એ `ffmpeg` ને શોધી નથી શકતું.

aaPanel → **Website** → `rtmp.akdwk.in` → **Config** → **PHP settings** (અથવા *Configuration file*) →
`open_basedir` વાળી લીટી શોધો → એના અંતે આ ઉમેરો:

```
:/usr/bin/:/usr/local/bin/:/tmp/
```

Save → **PHP restart** કરો.
*(સૌથી સહેલું: એ લીટીની શરૂઆતમાં `;` મૂકીને એને comment કરી દો.)*

### Step 1.4 — ચકાસો

Panel માં: **Health** → **Run health check**. હવે આવું દેખાવું જોઈએ:

| Service | અપેક્ષિત |
|---|---|
| Streaming Engine | 🟢 MediaMTX reachable, ffmpeg found |
| RTMP | 🟢 Port 1935 open |
| Queue | 🟢 Driver: database |

હજી લાલ હોય તો:
```bash
systemctl status mediamtx --no-pager
journalctl -u mediamtx -n 30 --no-pager
```

---

## ભાગ 2 — પહેલો Stream Key બનાવો

1. Panel → **Stream Keys** → **+ Generate Stream Key**
2. **Name**: `Dwarka Event` (કોઈ પણ નામ)
3. **Path ID**: `AKDWK-EVENT-001` (ખાલી છોડો તો આપોઆપ બનશે)
4. નીચેના બે options:
   - ☐ **Automatic mode** — OBS ચાલુ થતાં જ બધા platform પર આપોઆપ live (ટીક કરો તો બટન દબાવવું નહીં પડે)
   - ☐ **Record sessions** — recording જોઈતું હોય તો ટીક કરો
5. **Generate key** દબાવો

**⚠️ મહત્વનું:** key ફક્ત **એક જ વાર** આખી દેખાશે. તરત **Copy Stream Key** કરી safe જગ્યાએ રાખો.
ભૂલી જાઓ તો ચિંતા નહીં — **Regenerate** કરીને નવી બનાવી શકાય (પણ OBS માં નવી નાખવી પડશે).

---

## ભાગ 3 — OBS ગોઠવો

OBS Studio ખોલો → **Settings** → **Stream**:

| Field | શું નાખવું |
|---|---|
| Service | **Custom…** |
| Server | `rtmp://rtmp.akdwk.in/live` |
| Stream Key | જે copy કરી હતી તે |

પછી **Settings → Output** (Mode: Advanced):

| Setting | Value |
|---|---|
| Encoder | x264 (અથવા NVENC જો graphics card હોય) |
| Rate Control | CBR |
| Bitrate | **4500 kbps** (1080p 30fps માટે) |
| **Keyframe Interval** | **2** ← YouTube/Facebook માટે ફરજિયાત |
| Audio Bitrate | 160 kbps |

**Start Streaming** દબાવો → panel માં **Stream Test** page પર જાઓ →
"🟢 Incoming Stream Detected" દેખાવું જોઈએ, સાથે resolution/FPS/bitrate.

અહીં સુધી પહોંચ્યા એટલે અડધું કામ થઈ ગયું! 🎉

---

## ભાગ 4 — Platforms જોડો

### 4.1 — Custom RTMP (સૌથી સહેલું, કોઈ API નહીં)

કોઈ પણ platform કે જે RTMP આપે છે તેના માટે:

1. **Destinations** → **+ 📡 Custom RTMP**
2. **Name**: `મારી YouTube channel`
3. **RTMP URL** અને **Stream Key**: platform માંથી copy કરો
4. **Add destination** → પછી **Test** દબાવો → 🟢 pass આવવું જોઈએ

દરેક platform ની key ક્યાંથી મળે:

| Platform | ક્યાં જવું | RTMP URL |
|---|---|---|
| **YouTube** | studio.youtube.com → Go Live → Stream | `rtmp://a.rtmp.youtube.com/live2` |
| **Facebook Page** | facebook.com/live/producer → Streaming software | `rtmps://live-api-s.facebook.com:443/rtmp` |
| **Instagram** | instagram.com → Create → Live → **Live Producer** | એ page પર જે બતાવે તે |
| **Twitch** | dashboard.twitch.tv → Settings → Stream | `rtmp://live.twitch.tv/app` |
| **LinkedIn** | LinkedIn event બનાવો → **Custom stream (RTMP)** | એ page પર જે બતાવે તે |

> **Instagram વિશે સાચી વાત:** Instagram કોઈ official API આપતું નથી જેનાથી એક ક્લિકમાં Live શરૂ થાય.
> એકમાત્ર સત્તાવાર રસ્તો **Live Producer** છે (professional/creator account જોઈએ). ત્યાંથી મળતી
> RTMPS URL + key અહીં paste કરવાની. એ key દર વખતે નવી હોય છે, એટલે દરેક live પહેલાં update કરવી પડે.
> આપણે કોઈ ગેરકાયદે રસ્તો નથી વાપરતા — એ account બંધ કરાવી શકે.

### 4.2 — YouTube આપોઆપ (OAuth — એક વાર ગોઠવો, પછી હંમેશાં આપોઆપ)

આનાથી દર વખતે key copy કરવી નહીં પડે; system પોતે YouTube પર broadcast બનાવી દેશે.

1. [console.cloud.google.com](https://console.cloud.google.com) → **નવો Project** બનાવો
2. ડાબે **APIs & Services → Library** → `YouTube Data API v3` શોધો → **Enable**
3. **OAuth consent screen** → External → App name, email ભરો → Save
   → **Test users** માં તમારો Gmail ઉમેરો
4. **Credentials** → **Create Credentials** → **OAuth client ID** → Type: **Web application**
   → **Authorized redirect URIs** માં આ બરાબર આ જ paste કરો:
   ```
   https://rtmp.akdwk.in/admin/platforms/youtube/callback
   ```
5. Client ID અને Client secret copy કરો
6. Panel → **Settings → Platforms** → YouTube માં બંને paste → **Save**
7. **Destinations** → **▶️ Connect YouTube** → Google login → Allow
8. **Destinations** → **+ ▶️ YouTube Live** → Title, Description, Privacy (Public/Unlisted) → Save

⚠️ YouTube channel પર Live streaming enabled હોવું જોઈએ (Studio માં phone verify કર્યા પછી 24 કલાક લાગે).

### 4.3 — Facebook Page આપોઆપ (OAuth)

1. [developers.facebook.com](https://developers.facebook.com) → **My Apps → Create App** → type **Business**
2. **Add Product** → **Facebook Login** → Settings → **Valid OAuth Redirect URIs**:
   ```
   https://rtmp.akdwk.in/admin/platforms/facebook/callback
   ```
3. **App Settings → Basic** માંથી **App ID** અને **App Secret** copy કરો
4. Panel → **Settings → Platforms** → Meta માં paste → Save
5. **Destinations** → **📘 Connect Facebook** → login → તમારું Page select કરો
6. **Destinations** → **+ 📘 Facebook Page Live** → Page select કરો → Save

> **ધ્યાન રાખો:** Facebook ફક્ત **Page** પર જ API થી live આપે છે — personal profile કે Group પર નહીં.
> બીજા લોકો પણ વાપરે એ માટે Facebook નું **App Review** પસાર કરવું પડે (`pages_manage_posts`,
> `publish_video` permissions). ત્યાં સુધી ફક્ત તમે (app admin) જ વાપરી શકશો.
> ઝડપી રસ્તો જોઈતો હોય તો ભાગ 4.1 વાળી Custom RTMP રીત વાપરો — એ તરત કામ કરે છે.

---

## ભાગ 5 — Live જાઓ

1. OBS માં **Start Streaming**
2. Panel → **Live Stream** page
3. **▶ START LIVE** દબાવો
4. દરેક destination નું status જુઓ:
   🟡 connecting → 🟢 **live**
5. પૂરું થાય એટલે **■ STOP LIVE**, પછી OBS માં Stop Streaming

**Automatic mode** ચાલુ કર્યું હોય તો step 3 કરવાની જરૂર નથી — OBS ચાલુ થતાં જ બધે live થઈ જશે.

**એક platform fail થાય તો?** ચિંતા નહીં — બાકીના ચાલુ જ રહેશે. System જાતે ફરી જોડાવાનો પ્રયત્ન કરશે
(5 સેકન્ડ → 15 → 30 → 60 → 120). **Live Logs** માં બધું દેખાશે.

---

## ભાગ 6 — બીજા users ઉમેરો

**Users** → **+ Add user** → નામ, email, password → **Role** પસંદ કરો:

| Role | શું કરી શકે |
|---|---|
| **Super Admin** | બધું જ (settings, updates, backups) — તમારા માટે |
| **Admin** | Streaming, destinations, users — settings નહીં |
| **Operator** | ફક્ત live start/stop, schedule — cameraman/operator માટે ✅ |
| **Viewer** | ફક્ત જોઈ શકે, કંઈ બદલી ન શકે — client ને બતાવવા માટે |

### દરેક user ને પોતાની અલગ stream key આપવી

1. **Stream Keys** → **+ Generate Stream Key**
2. **Name**: `AKDWK-HOTEL-001` જેવું
3. **Assign to user**: જે user ને આપવી હોય તે પસંદ કરો
4. એ key તેને આપો → એ પોતાના OBS માં નાખશે

પછી **Destinations** માં દરેક destination ને **"Bind to stream key"** થી એ જ key સાથે જોડો.
એટલે એ user ના stream ફક્ત એના જ platforms પર જશે, બીજાના પર નહીં.

---

## ભાગ 7 — સલામતી (જરૂર કરો)

1. **Profile** → **Two-factor authentication** → Enable → Google Authenticator app થી scan →
   6-આંકડાનો code નાખો → **Recovery codes સાચવી રાખો**
2. **Settings → Security** → Session timeout, max login attempts ચકાસો
3. **Settings → E-mail** → SMTP ભરો (alerts આવે એ માટે)
4. **Backups** → **Create backup now** ચલાવીને ચકાસો કે backup બને છે
   (આપોઆપ backup રોજ રાત્રે 2:30 વાગ્યે થાય છે)

---

## ભાગ 8 — Auto-update ગોઠવો (optional પણ ઉપયોગી)

એક વાર ગોઠવ્યા પછી નવા version panel માંથી જ install થશે — files ક્યારેય જાતે upload નહીં કરવી પડે.

1. GitHub → Settings → Developer settings → **Personal access tokens** → **Fine-grained**
2. Repository access: ફક્ત તમારી `rtmp` repository → Permissions: **Contents: Read-only**
3. Token copy કરો
4. Panel → **Updates** → Repository: `akshaykananidwk/rtmp`, Branch: `main`, Token paste → **Save**
5. **CHECK FOR UPDATE** → નવું version હોય તો **UPDATE NOW**

Update પહેલાં આપોઆપ backup થાય છે; કંઈ બગડે તો આપોઆપ જૂનું version પાછું આવી જાય છે.

---

## ઝડપી સમસ્યા-નિવારણ

| સમસ્યા | ઉપાય |
|---|---|
| OBS: "Failed to connect" | `systemctl status mediamtx` · port 1935 firewall માં ખુલ્લો છે? · key revoke તો નથી થઈ? |
| "Incoming Stream Detected" નથી આવતું | `journalctl -u mediamtx -n 30` · `php artisan stream:sync` ચલાવો |
| Destination `connecting` માં અટકે | **Live Logs** જુઓ · destination **Test** કરો · platform ની key સાચી છે? |
| બધું `pending` રહે | `systemctl status akstream-supervisor` — બંધ હોય તો `systemctl restart akstream-supervisor` |
| Health: Streaming Engine લાલ | ભાગ 1.3 (open_basedir) ફરી કરો |
| Instagram key કામ ન કરે | Live Producer ની key દર વખતે નવી હોય છે — દરેક live પહેલાં update કરો |
| Facebook: permission error | App Review બાકી છે — ત્યાં સુધી Custom RTMP રીત વાપરો (ભાગ 4.1) |

વધુ માટે: `docs/TROUBLESHOOTING.md`

---

## યાદ રાખવા જેવી 5 વાતો

1. **Stream key ગુપ્ત રાખો** — લીક થાય તો તરત **Regenerate** કરો
2. **Keyframe interval = 2** રાખો, નહીં તો YouTube/Facebook reject કરે
3. **એક platform fail** થાય તો બાકીના ચાલુ જ રહે છે — ગભરાવું નહીં
4. **Instagram/LinkedIn** માટે દર વખતે નવી key નાખવી પડે (એમનો API નથી)
5. **Backup** ચાલુ રાખો — update પહેલાં આપોઆપ થાય જ છે

---

**મદદ જોઈએ તો:** AK COMPUTER · Akshay Kanani · 📞 9978123146
