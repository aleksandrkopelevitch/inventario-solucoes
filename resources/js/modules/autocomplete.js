import * as ajaxModule from './ajax'

export function init() {
    let jsAutocomplete = document.querySelectorAll('[data-ak-autocomplete]')
    if (jsAutocomplete[0]) {
        jsAutocomplete.forEach((inputText) => {

            let data = inputText.dataset.akAutocomplete ? JSON.parse(inputText.dataset.akAutocomplete) : {}

            let options = {
                once: data.once || false,
            }

            if (!data.resultsId) {
                return false
            }

            let timer      = 0
            let ignoreKeys = ['ArrowUp', 'ArrowRight', 'ArrowDown', 'ArrowLeft', 'Control', 'Alt', 'Shift', 'Meta']

            if (inputText.searchOnKeyUp !== true) {
                inputText.addEventListener('keyup', (event) => {
                    if (ignoreKeys.indexOf(event.key) <= -1) {
                        let lastWord = inputText.value.split(' ').pop()
                        if (lastWord.length >= 2) {
                            clearTimeout(timer)
                            timer = setTimeout(() => {
                                loadResults(inputText.id, data.resultsId, data.url, lastWord)
                            }, 400)
                        } else {
                            hideResultsContainer(data.resultsId)
                        }
                    }
                }, options)
                inputText.searchOnKeyUp = true
            }
        })
    }
}

/*------------------------------------------------
    Load data & populate select dropdown
-------------------------------------------------*/
export function loadResults(inputTextId, resultsId, searchURL, searchTerms) {

    let resultsContainer = document.getElementById(resultsId)
    if (!resultsContainer) return false

    //let limit      = inputText.dataset.limit || ''
    ajaxModule.init('GET', searchURL + '?searchTerms=' + searchTerms)
        .then((response) => response.json())
        .then((data) => {
            resultsContainer.innerHTML = data.content
            showResultsContainer(resultsId)
            attachActionsToResults(inputTextId, resultsId)
        })
        .catch(() => {
            hideResultsContainer(resultsId)
        })
}

export function attachActionsToResults(inputTextId, resultsId) {

    let resultsContainer = document.getElementById(resultsId)
    let inputText        = document.getElementById(inputTextId)
    if (!resultsContainer || !inputText) return false
    let list = resultsContainer.firstElementChild

    inputText.addEventListener('keyup', (event) => {
        if (event.key === 'ArrowDown'){
            if (list)
                list.firstElementChild.focus()
        }
    })

    if (list){
        for (let listItem = list.firstElementChild; listItem !== null; listItem = listItem.nextElementSibling) {
            listItem.addEventListener('keyup', (event) => {

                if (event.key === 'ArrowDown') {
                    (listItem.nextElementSibling) ? listItem.nextElementSibling.focus() : null
                    document.body.classList.add('overflow-y-hidden')
                }

                if (event.key === 'ArrowUp') {
                    (listItem.previousElementSibling) ? listItem.previousElementSibling.focus() : null
                    document.body.classList.add('overflow-y-hidden')
                }

                if (event.key === 'Enter') {
                    replaceSearchTermWithListItemResult(inputTextId, listItem.innerText)
                    inputText.focus()
                    list.innerHTML = ''
                    list.classList.add('hidden')
                    document.body.classList.remove('overflow-y-hidden')
                }

            }, {once: false})

            listItem.addEventListener('click', (event) => {
                replaceSearchTermWithListItemResult(inputTextId, listItem.innerText)
                inputText.focus()
                list.innerHTML = ''
                list.classList.add('hidden')
                document.body.classList.remove('overflow-y-hidden')
            }, {once: false})
        }
    }
}


export function replaceSearchTermWithListItemResult(inputTextId, textToAdd) {
    let inputText = document.getElementById(inputTextId)
    if (!inputText) return false

    let inputContentWithoutLastWord = inputText.value.split(' ').slice(0, -1).join(' ')
    inputText.value                 = inputContentWithoutLastWord + ' ' + textToAdd
    inputText.value = inputText.value.trim()
}

export function hideResultsContainer(resultsId) {
    let resultsContainer = document.getElementById(resultsId)
    if (!resultsContainer) return false

    if (!resultsContainer.classList.contains('hidden'))
        resultsContainer.classList.add('hidden')
}

export function showResultsContainer(resultsId) {
    let resultsContainer = document.getElementById(resultsId)
    if (!resultsContainer) return false

    if (resultsContainer.classList.contains('hidden'))
        resultsContainer.classList.remove('hidden')
}
