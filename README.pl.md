# Laravel PayPo

[![tests](https://github.com/beziworld/laravel-paypo/actions/workflows/tests.yml/badge.svg)](https://github.com/beziworld/laravel-paypo/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/beziworld/laravel-paypo.svg)](https://packagist.org/packages/beziworld/laravel-paypo)
[![License](https://img.shields.io/packagist/l/beziworld/laravel-paypo.svg)](LICENSE.md)

Płatności odroczone [PayPo](https://paypo.pl) („kup teraz, zapłać później”) dla Laravela, oparte na REST API v3:

- rejestracja transakcji z adresem formularza PayPo do przekierowania kupującego
- status transakcji ze statusem rozliczenia i zwrotami
- potwierdzenie realizacji zamówienia (`COMPLETED`), anulowanie oraz pełne i częściowe zwroty
- trasa powiadomień z weryfikacją podpisu `X-PayPo-Signature` (HMAC-SHA256) i zdarzeniami Laravela
- OAuth2 client credentials z tokenem cache'owanym osobno dla każdego sprzedawcy
- wielu sprzedawców: nazwane połączenia, dane podawane w locie przez `using()` i resolver dla powiadomień tenantów
- sandbox, produkcja i hosty innych krajów (np. `api.paypo.ro`)

[English documentation](README.md)

## Dlaczego ten pakiet

Jedyną utrzymywaną biblioteką PHP jest [answear/pay-po](https://packagist.org/packages/answear/pay-po). Nie zależy
od frameworka i trzyma jednego sprzedawcę w statycznej, globalnej konfiguracji. Ten pakiet dokłada to, czego
potrzebuje sklep na Laravelu: trasę powiadomień z weryfikacją podpisu i zdarzeniami, wiele kont sprzedawców w jednej
aplikacji (także tenantów z danymi w bazie), tokeny w cache Laravela, transport zgodny z `Http::fake` i typowane
obiekty danych.

## Instalacja

```bash
composer require beziworld/laravel-paypo
php artisan vendor:publish --tag=paypo-config   # opcjonalnie
```

```dotenv
PAYPO_CLIENT_ID=...            # panel sprzedawcy: OAuth ClientId
PAYPO_CLIENT_SECRET=...        # panel sprzedawcy: OAuth ClientSecret
PAYPO_MERCHANT_ID=...          # merchantId; powiadomienia innych sprzedawców są odrzucane
PAYPO_MERCHANT_API_KEY=...     # merchantApiKey; weryfikuje nagłówek X-PayPo-Signature
PAYPO_SHOP_ID=                 # opcjonalny punkt sprzedaży (shopId)
PAYPO_SANDBOX=true             # api.sandbox.paypo.pl; false = api.paypo.pl
PAYPO_RETURN_URL=https://sklep.example.com/paypo/powrot
PAYPO_CANCEL_URL=              # opcjonalnie; kupujący zrezygnował z PayPo
```

Powiadomienia trafiają na trasę pakietu `POST /paypo/notification/{connection}`; jej adres dla danego połączenia
jest automatycznie wysyłany jako `notifyUrl`.

## Przebieg płatności

```php
use Beziworld\PayPo\Data\Address;
use Beziworld\PayPo\Data\Customer;
use Beziworld\PayPo\Data\TransactionRequest;
use Beziworld\PayPo\Enums\Shipment;
use Beziworld\PayPo\Facades\PayPo;
use Beziworld\PayPo\Support\Amount;

$created = PayPo::createTransaction(new TransactionRequest(
    referenceId: $order->number,
    amount: Amount::toMinor($order->total),          // "249.90" -> 24990 (grosze)
    customer: new Customer('Anna', 'Nowak', 'anna@example.com', '+48500123456'),
    billingAddress: new Address('Kredytowa', '9a', 'Warszawa', '00-950', flat: '3'),
    shippingAddress: null,                            // domyślnie adres rozliczeniowy
    description: "Zamówienie {$order->number}",
    shipment: Shipment::ParcelLocker,
));

$order->update(['paypo_transaction_id' => $created->transactionId]);

return redirect()->away($created->redirectUrl);
```

Kupujący wraca na adres powrotu z `?status=OK` lub `?status=ERR`. Ten parametr służy tylko do wyświetlenia
komunikatu; stan zamówienia wynika z powiadomień albo z `PayPo::transaction()`.

### Powiadomienia i zdarzenia

```php
use Beziworld\PayPo\Events\TransactionAccepted;

Event::listen(function (TransactionAccepted $event) {
    $order = Order::where('paypo_transaction_id', $event->notification->transactionId)->firstOrFail();

    if ($event->notification->amount === $order->total_in_grosze) {
        $order->markAsPaidWithPayPo();     // wyślij towar, potem potwierdź (niżej)
    }
});
```

| Zdarzenie | Kiedy |
|---|---|
| `TransactionStatusChanged` | każde autentyczne powiadomienie o zmianie statusu lub kwoty |
| `TransactionAccepted` | PayPo przyznało płatność odroczoną |
| `TransactionCompleted` | zamówienie potwierdzone, gotowe do rozliczenia |
| `TransactionRejected` | odmowa płatności odroczonej (później może jeszcze zostać zaakceptowana) |
| `TransactionCanceled` | transakcja anulowana |
| `SettlementReported` | wygenerowano specyfikację przelewu (`settlementStatus`, `message`) |

Każde zdarzenie zawiera obiekt `Notification` i nazwę połączenia. PayPo ponawia powiadomienia przez 24 godziny,
dopóki nie otrzyma HTTP 200, a statusy mogą przyjść w innej kolejności, więc listenery muszą być idempotentne;
porównuj `TransactionStatus::rank()` z zapisanym statusem, aby pominąć nieaktualne.

Podpis to `base64(HMAC-SHA256("POST+" . ścieżka . "+" . json, merchantApiKey))`. Powiadomienia bez podpisu lub
z błędnym podpisem, innego sprzedawcy albo z nieznanym statusem dostają HTTP 400. `PAYPO_VERIFY_STATUS=true`
dodatkowo pobiera transakcję z API przed wysłaniem zdarzeń (HTTP 503 przy niedostępnym API wymusza ponowienie).

Własny kontroler może użyć `PayPo::parseNotification($request->getContent(), $request->header('X-PayPo-Signature'), '/webhooks/paypo')`.

## Potwierdzenie, anulowanie, zwroty, status

```php
PayPo::confirm($transactionId);                    // COMPLETED: zamówienie zrealizowane, PayPo je rozlicza
PayPo::cancel($transactionId);                     // przed COMPLETED; potem ApiException::isConflict()
PayPo::refund($transactionId, 8655, "zwrot-{$return->id}");   // grosze, własny identyfikator (maks. 68 znaków)

$transaction = PayPo::transaction($transactionId);
$transaction->status;                 // TransactionStatus::Completed
$transaction->settlementStatus;       // SettlementStatus::Paid
$transaction->amountDecimal();        // "162.45" (aktualna wartość po zwrotach)
$transaction->refunds;                // list<Refund> albo null, gdy rozszerzona odpowiedź nie jest włączona
$transaction->findRefund("zwrot-{$return->id}");
```

Niepotwierdzone zamówienia PayPo może anulować po czasie ustalonym w umowie, dlatego potwierdzaj je po wysyłce.
Zwrot dla transakcji `ACCEPTED` automatycznie ją potwierdza; zwroty nie mogą zwiększyć wartości zamówienia.

## Wielu sprzedawców

```php
// config/paypo.php: 'connections' => ['default' => [...], 'outlet' => [...]]
PayPo::connection('outlet')->createTransaction($request);   // notifyUrl: /paypo/notification/outlet

// Dane dostępowe zapisane per tenant
PayPo::using($tenant->paypoCredentials())->transaction($id);

// Tenanci odbierający powiadomienia: nadaj im nazwę i zarejestruj resolver
PayPo::resolveConnectionsUsing(fn (string $name) => Tenant::where('paypo_key', $name)->first()?->paypoCredentials());
PayPo::connection($tenant->paypo_key)->createTransaction($request);   // notifyUrl: /paypo/notification/{key}
```

Każdy sprzedawca ma własny token OAuth w cache. Dla rynku rumuńskiego ustaw `PAYPO_BASE_URL=https://api.paypo.ro/v3`
i `PAYPO_CURRENCY=RON` (albo te same klucze w połączeniu).

## Błędy

| Wyjątek | Kiedy |
|---|---|
| `ApiException` | PayPo odrzuciło żądanie lub jest nieosiągalne: `httpStatus()`, błędy walidacji `errors()` (ścieżka i komunikat), `payload()`, `isConflict()`, `isNotFound()`, `isRateLimited()` z `retryAfter()` |
| `InvalidNotification` | powiadomienie bez podpisu lub z błędnym podpisem, innego sprzedawcy albo uszkodzone |
| `InvalidConfiguration` | brak danych dostępowych lub adresów, nieznane połączenie |

Wszystkie dziedziczą po `PayPoException`. Rejestracja, potwierdzenie, anulowanie i zwroty nigdy nie są ponawiane
automatycznie; jedyne ponowienie następuje po HTTP 401, gdy token wygasł i żądanie nie zostało przetworzone.
Odczyt statusu jest ponawiany przy błędach połączenia i HTTP 5xx (`PAYPO_RETRIES`, `PAYPO_RETRY_DELAY`).

## Rozwój

```bash
composer install
composer ci   # pint --test, phpstan (poziom 8), pest
```

Implementacja jest zgodna z oficjalną specyfikacją „PayPo API v.3.1” (wersja dokumentu 3.1.2). Dane do sandboxa
PayPo wydaje sprzedawcom; testy używają `Http::fake` z przykładami ze specyfikacji.

## Autor

Pakiet tworzy i rozwija **Kamil Ćwiertnia**, [beziworld.eu](https://beziworld.eu), [biuro@beziworld.eu](mailto:biuro@beziworld.eu).
Zgłoszenia: [GitHub issues](https://github.com/beziworld/laravel-paypo/issues).
Potrzebujesz integracji? Zapraszam do kontaktu przez [beziworld.eu](https://beziworld.eu).

## Licencja

MIT. Zobacz [LICENSE.md](LICENSE.md). Pakiet nie jest powiązany z PayPo sp. z o.o.
