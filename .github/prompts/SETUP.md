# Konfiguracja codziennego zbieracza newsów SEO/GEO

Workflow: `.github/workflows/daily-seo-geo-news.yml`
Prompt: `.github/prompts/daily-seo-geo-news.md`

## 1. Wymagane sekrety w repozytorium

Settings → Secrets and variables → Actions → **New repository secret**

| Nazwa sekretu        | Opis                                                                                              |
|----------------------|---------------------------------------------------------------------------------------------------|
| `ANTHROPIC_API_KEY`  | Klucz API z https://console.anthropic.com – z włączonym dostępem do Claude Code Action.            |
| `GDRIVE_CREDENTIALS` | Plik JSON z poświadczeniami OAuth Google Drive (Service Account lub OAuth user credentials), w jednej linii. Konto musi mieć dostęp do folderu `SEO_GEO_News`. |

### Jak wygenerować `GDRIVE_CREDENTIALS`

Wariant A – **Service Account** (zalecany dla automatów):

1. https://console.cloud.google.com → utwórz projekt (lub użyj istniejącego).
2. Włącz **Google Drive API**.
3. IAM & Admin → Service Accounts → Create → pobierz klucz JSON.
4. Udostępnij folder `SEO_GEO_News` na adres e-mail Service Accounta (z prawem do edycji).
5. Wklej całą zawartość JSON jako wartość sekretu `GDRIVE_CREDENTIALS`.

Wariant B – **OAuth user credentials** (jeśli chcesz, by pliki należały do Twojego konta):

1. Wygeneruj OAuth client (Desktop app) w Google Cloud Console.
2. Uruchom lokalnie `npx @modelcontextprotocol/server-gdrive auth` i przejdź flow w przeglądarce.
3. Wynikowy plik z tokenem wklej do sekretu `GDRIVE_CREDENTIALS`.

## 2. Harmonogram

Cron w workflow: `0 6 * * *` (codziennie o **06:00 UTC**).

- **Lato w PL (CEST, UTC+2):** odpali się o **08:00** czasu polskiego ✅
- **Zima w PL (CET, UTC+1):** odpali się o **07:00** czasu polskiego.
  Jeśli zależy Ci na dokładnie 8:00 cały rok, możesz utworzyć dwa
  wpisy cron (jeden na sezon zimowy `0 7 * * *`, drugi na letni `0 6 * * *`)
  i włączać je ręcznie, albo zostawić tak jak jest.

> GitHub Actions nie obsługuje stref czasowych w `cron` – wszystkie wartości
> są w UTC.

## 3. Ręczne uruchomienie (test)

Actions → **Daily SEO/GEO News Collector** → **Run workflow** (przycisk
z prawej). Dzięki `workflow_dispatch:` możesz odpalić ad-hoc.

## 4. Deduplikacja

Prompt instruuje Claude, by przed dodaniem nowych newsów:

1. Wylistował zawartość folderu `SEO_GEO_News`.
2. Wyciągnął tytuły i URL-e z istniejących plików.
3. Pomijał materiały, które już są opisane.

Pliki są nazywane wg schematu `YYYY-MM-DD_NN_slug.txt`, więc historia
zostaje uporządkowana chronologicznie.

## 5. Alternatywa: scheduled trigger w Claude Code on the web

Jeśli wolisz uniknąć GitHub Actions, możesz to samo skonfigurować w UI:

1. Wejdź na https://claude.ai/code
2. Otwórz to repo / tę sesję.
3. W ustawieniach sesji wybierz **Triggers** → **Scheduled** → 08:00
   Europe/Warsaw, codziennie.
4. Jako prompt wklej zawartość `.github/prompts/daily-seo-geo-news.md`.

Dokumentacja: https://code.claude.com/docs/en/claude-code-on-the-web
