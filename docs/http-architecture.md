# HTTP architecture

HTTP implementation is organized by responsibility at the `src` root rather than under a shared `Http` namespace:

- `Contracts/Http`: public transport capability contracts (`TransportInterface`, `AsyncTransportInterface`, `CancellableTransportInterface`).
- `Transport/Drivers`: concrete Guzzle, Amp, Impity, php-rnet and browser-backed drivers.
- `Transport/TransportRegistry.php`: named transport factory registry.
- `Fetching`: fetch/download orchestration and browser fetch orchestration.
- `Options` and `Response`: request configuration and normalized response value objects.
- `Policy`: URL normalization, host allow-lists and public-network validation.
- `Retry`, `Proxy`, `Throttle`: independent transport decorators and request policies.
- `Session`: cookie-aware session facade.

There are no aliases for former HTTP namespaces and no legacy transport contracts. External transports implement `HElturkey\\WebTrawler\\Contracts\\Http\\TransportInterface`; asynchronous drivers additionally implement `AsyncTransportInterface`, and may implement `CancellableTransportInterface` when cancellation is genuinely supported.
