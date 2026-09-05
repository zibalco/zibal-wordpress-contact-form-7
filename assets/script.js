(function() {
    'use strict';

    function redirectToZibal(url) {
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('referrerpolicy', 'origin');
        link.style.display = 'none';
        document.body.appendChild(link);
        link.click();
    }
    
    function initZibalCF7() {
        document.addEventListener('wpcf7submit', function(event) {
            const form = event.target;
            const zibalButton = form.querySelector('.zibal-payment-button');
            const formId = form.querySelector('input[name="_wpcf7"]')?.value;

            if (redirectFromApiResponse(event.detail, zibalButton)) {
                return;
            }
            
            if (!formId) {
                resetButton(zibalButton);
                return;
            }
            
            const clientToken = form.querySelector('input[name="_zibal_client_token"]')?.value || '';
            const restUrlBase = (typeof zibalCF7 !== 'undefined' && zibalCF7.restUrl)
                ? zibalCF7.restUrl + 'redirect/' + formId
                : window.location.origin + '/wp-json/zibal-cf7/v1/redirect/' + formId;
            const restUrl = clientToken
                ? restUrlBase + '?token=' + encodeURIComponent(clientToken)
                : restUrlBase;
            
            fetch(restUrl, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'same-origin'
            })
                .then(function(response) {
                    if (response.status === 404) {
                        resetButton(zibalButton);
                        return null;
                    }
                    
                    if (!response.ok) {
                        throw new Error('HTTP error! status: ' + response.status);
                    }
                    
                    return response.json();
                })
                .then(function(data) {
                    if (!data) {
                        return;
                    }
                    
                    if (data.success && data.redirect_url) {
                        if (zibalButton) {
                            const buttonText = zibalButton.querySelector('.zibal-button-text');
                            if (buttonText) {
                                buttonText.textContent = 'در حال انتقال...';
                            }
                        }
                        
                        redirectToZibal(data.redirect_url);
                    } else {
                        resetButton(zibalButton);
                    }
                })
                .catch(function(error) {
                    console.error('[Zibal CF7] Error:', error);
                    resetButton(zibalButton);
                });
        }, false);

        document.addEventListener('wpcf7mailsent', function(event) {
            const form = event.target;
            const zibalButton = form.querySelector('.zibal-payment-button');
            redirectFromApiResponse(event.detail, zibalButton);
        }, false);
        
        document.addEventListener('wpcf7invalid', function(event) {
            const form = event.target;
            const zibalButton = form.querySelector('.zibal-payment-button');
            resetButton(zibalButton);
        }, false);
        
        document.addEventListener('wpcf7mailfailed', function(event) {
            const form = event.target;
            const zibalButton = form.querySelector('.zibal-payment-button');
            resetButton(zibalButton);
        }, false);
        
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('.wpcf7-form');
            
            forms.forEach(function(form) {
                const zibalButton = form.querySelector('.zibal-payment-button');
                
                if (!zibalButton) {
                    return;
                }
                
                form.addEventListener('submit', function(e) {
                    if (zibalButton.classList.contains('loading')) {
                        e.preventDefault();
                        return false;
                    }
                    
                    zibalButton.classList.add('loading');
                    zibalButton.disabled = true;
                    
                    const buttonText = zibalButton.querySelector('.zibal-button-text');
                    const buttonSpinner = zibalButton.querySelector('.zibal-button-spinner');
                    
                    if (buttonText) {
                        buttonText.setAttribute('data-original-text', buttonText.textContent);
                        buttonText.style.opacity = '0.7';
                    }
                    if (buttonSpinner) {
                        buttonSpinner.style.display = 'inline-block';
                    }
                    
                    const formId = form.querySelector('input[name="_wpcf7"]')?.value;
                    if (formId) {
                        setTimeout(function() {
                            checkAndRedirect(formId, zibalButton);
                        }, 2000);
                    }
                });
            });
        });
    }
    
    function checkAndRedirect(formId, zibalButton) {
        const form = zibalButton ? zibalButton.closest('form') : null;
        const clientToken = form?.querySelector('input[name="_zibal_client_token"]')?.value || '';
        const restUrlBase = (typeof zibalCF7 !== 'undefined' && zibalCF7.restUrl)
            ? zibalCF7.restUrl + 'redirect/' + formId
            : window.location.origin + '/wp-json/zibal-cf7/v1/redirect/' + formId;
        const restUrl = clientToken
            ? restUrlBase + '?token=' + encodeURIComponent(clientToken)
            : restUrlBase;
        
        fetch(restUrl, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
            },
            credentials: 'same-origin'
        })
            .then(function(response) {
                if (response.status === 404) {
                    return null;
                }
                
                if (!response.ok) {
                    throw new Error('HTTP error! status: ' + response.status);
                }
                
                return response.json();
            })
            .then(function(data) {
                if (!data) {
                    return;
                }
                
                if (data.success && data.redirect_url) {
                    redirectToZibal(data.redirect_url);
                } else {
                    resetButton(zibalButton);
                }
            })
            .catch(function(error) {
                console.error('[Zibal CF7] Fallback error:', error);
                resetButton(zibalButton);
            });
    }

    function redirectFromApiResponse(detail, zibalButton) {
        const redirectUrl = detail?.apiResponse?.zibal?.redirect_url || '';

        if (!redirectUrl) {
            return false;
        }

        if (zibalButton) {
            const buttonText = zibalButton.querySelector('.zibal-button-text');
            if (buttonText) {
                buttonText.textContent = 'در حال انتقال...';
            }
        }

        redirectToZibal(redirectUrl);
        return true;
    }
    
    function resetButton(zibalButton) {
        if (!zibalButton) return;
        
        zibalButton.classList.remove('loading');
        zibalButton.disabled = false;
        
        const buttonText = zibalButton.querySelector('.zibal-button-text');
        const buttonSpinner = zibalButton.querySelector('.zibal-button-spinner');
        
        if (buttonText) {
            buttonText.style.opacity = '1';
            const originalText = buttonText.getAttribute('data-original-text');
            if (originalText) {
                buttonText.textContent = originalText;
            }
        }
        
        if (buttonSpinner) {
            buttonSpinner.style.display = 'none';
        }
    }
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(initZibalCF7, 100);
        });
    } else {
        setTimeout(initZibalCF7, 100);
    }
    
})();
