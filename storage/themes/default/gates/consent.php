<section class="section-half-rounded bg-light py-4 py-sm-0">
    <div class="container-fluid d-flex flex-column">
        <div class="row align-items-center min-vh-100">
            <div class="col-md-6 col-lg-5 col-xl-4 mx-auto">
                <div class="card shadow-lg border-0 mb-0">
                    <div class="card-body py-5 px-sm-5 text-center">
                        <div class="mb-4">
                            <span class="btn btn-primary btn-icon-only rounded-circle mb-3" style="width: 56px; height: 56px; line-height: 56px; font-size: 24px; display: inline-flex; align-items: center; justify-content: center;">
                                <i data-feather="map-pin"></i>
                            </span>
                            <h3 class="h4 mb-2 fw-bold"><?php ee('Localisation géographique') ?></h3>
                            <p class="text-muted small">
                                <?php ee('Cette campagne souhaite affiner ses statistiques de consultation. Souhaitez-vous partager votre position régionale ?') ?>
                            </p>
                        </div>

                        <div id="consent-status" class="alert alert-info py-2 d-none small"></div>

                        <div class="d-grid gap-2 mt-4">
                            <button type="button" id="btn-share-location" class="btn btn-primary btn-block fw-bold py-2">
                                <i data-feather="check" class="me-1"></i> <?php ee('Autoriser et continuer') ?>
                            </button>
                            <a href="<?php echo e($targetUrl) ?>" id="btn-skip-location" class="btn btn-outline-secondary btn-block mt-2">
                                <?php ee('Continuer sans partager') ?>
                            </a>
                        </div>

                        <div class="text-muted mt-4 mb-0 text-start border-top pt-3" style="font-size: 0.75rem;">
                            <p class="mb-1">
                                <strong><?php ee('Confidentialité et traitement des données :') ?></strong>
                                <?php ee('Vos coordonnées GPS brutes ne sont pas enregistrées dans notre base de données ; seuls le pays, la région administrative et la ville correspondante sont conservés pour les statistiques.') ?>
                            </p>
                            <p class="mb-0">
                                <?php if (!empty($geocoderInfo['sends_external'])): ?>
                                    <?php ee('En cliquant sur « Autoriser et continuer », vos coordonnées GPS sont transmises à un fournisseur externe de géocodage inverse') ?><?php echo !empty($geocoderInfo['provider_name']) ? ' (' . htmlspecialchars($geocoderInfo['provider_name'], ENT_QUOTES, 'UTF-8') . ')' : ''; ?> <?php ee('afin de déterminer votre région ou votre ville.') ?>
                                <?php else: ?>
                                    <?php ee('Si un fournisseur externe de géocodage inverse est activé, vos coordonnées GPS peuvent lui être transmises ponctuellement afin de déterminer votre région ou votre ville. Vous pouvez continuer sans partager votre position.') ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
document.addEventListener("DOMContentLoaded", function () {
    var targetUrl = <?php echo json_encode($targetUrl); ?>;
    var consentEndpoint = <?php echo json_encode(route('link.geoconsent', [$url->alias ?: $url->custom])); ?>;
    var csrfToken = <?php echo json_encode(csrf_token() ?? ''); ?>;
    var consentNonce = <?php echo json_encode($consentNonce ?? ''); ?>;

    var shareBtn = document.getElementById("btn-share-location");
    var skipBtn = document.getElementById("btn-skip-location");
    var statusEl = document.getElementById("consent-status");

    function proceedToTarget() {
        window.location.href = targetUrl;
    }

    if (shareBtn) {
        shareBtn.addEventListener("click", function () {
            if (!navigator.geolocation) {
                proceedToTarget();
                return;
            }

            shareBtn.disabled = true;
            shareBtn.innerText = "<?php echo addslashes(e('Localisation en cours...')); ?>";

            var timeoutGuard = setTimeout(function () {
                proceedToTarget();
            }, 3000);

            navigator.geolocation.getCurrentPosition(
                function (position) {
                    clearTimeout(timeoutGuard);

                    var rawAccuracy = (position.coords && typeof position.coords.accuracy === "number" && isFinite(position.coords.accuracy) && position.coords.accuracy > 0)
                        ? position.coords.accuracy
                        : null;

                    var payload = {
                        latitude: position.coords.latitude,
                        longitude: position.coords.longitude,
                        accuracy: rawAccuracy,
                        consent_token: consentNonce,
                        _token: csrfToken
                    };

                    fetch(consentEndpoint, {
                        method: "POST",
                        headers: {
                            "Content-Type": "application/json",
                            "X-CSRF-TOKEN": csrfToken,
                            "X-Requested-With": "XMLHttpRequest"
                        },
                        body: JSON.stringify(payload)
                    })
                    .then(function () {
                        proceedToTarget();
                    })
                    .catch(function () {
                        proceedToTarget();
                    });
                },
                function (error) {
                    clearTimeout(timeoutGuard);
                    proceedToTarget();
                },
                {
                    enableHighAccuracy: false,
                    timeout: 2500,
                    maximumAge: 60000
                }
            );
        });
    }
});
</script>
