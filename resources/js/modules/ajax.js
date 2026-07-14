export async function init(method, url, formData = null) {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    const options = {
        method: method,
        headers: {
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
        },
    };

    if (formData) {
        options.body = formData;  // If we have form data, add it to the request body
    }

    const response = await fetch(url, options);

    if (!response.ok) {
        // If the response is not ok, throw an error with the response details
        const error = new Error(`HTTP error! Status: ${response.status}`);
        error.response = response; // Attach the response to the error
        throw error;
    }

    return response; // Return the full response to allow status checking in the caller
}

export function includeScripts(target) {
    let scripts = target.querySelectorAll('script')

    scripts.forEach((script) => {
        let scriptTag = document.createElement("script")

        if (script.getAttribute('src') !== null) {
            // Prevent adding the same external script multiple times
            if (!document.querySelector(`script[src="${script.getAttribute('src')}"]`)) {
                scriptTag.setAttribute('src', script.getAttribute('src'))
            }
        } else {
            // Inline scripts should be executed immediately
            scriptTag.textContent = script.textContent
        }

        // Append the new script to the target
        target.appendChild(scriptTag)
    })
}