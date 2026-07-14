export function init() {
    let makeSlugTriggers = document.querySelectorAll('[data-ak-make-slug]')
    if (makeSlugTriggers[0]) {
        makeSlugTriggers.forEach((makeSlugTrigger) => {
            let data          = makeSlugTrigger.dataset.akMakeSlug ? JSON.parse(makeSlugTrigger.dataset.akMakeSlug) : {}
            let event         = data.event || 'click'
            let sourceElement = document.getElementById(data.sourceId) || null
            let targetElement = document.getElementById(data.targetId) || null
            let options       = {
                once: data.once || false,
            }

            if (makeSlugTrigger.eventAdded !== true) {
                makeSlugTrigger.addEventListener(event, (event) => {
                    writeSlugOnTargetElement(sourceElement, targetElement)
                }, options)
                makeSlugTrigger.eventAdded = true
            }

            /*makeSlugTrigger.event         = makeSlugTrigger.dataset.event || 'click'
            makeSlugTrigger.sourceElement = document.getElementById(makeSlugTrigger.dataset.sourceId) || null
            makeSlugTrigger.targetElement = document.getElementById(makeSlugTrigger.dataset.targetId) || null*/
            //makeSlugTrigger.addEventListener(makeSlugTrigger.event, makeSlug, false)
        })
    }
}

export function string2Float(value) {
    let newValue = value.replaceAll('.', '')
    newValue     = newValue.replace(',', '.')
    return parseFloat(newValue)
}

export function getRandomId() {
    return Math.random().toString(36).substr(2, 9)
}

export function wordCount(fieldId, maxWords, counterContainerId, event) {
    let field            = document.getElementById(fieldId)
    let counterContainer = document.getElementById(counterContainerId)
    let wordMatcher      = field.value.match(/\S+/g)

    let words = wordMatcher ? wordMatcher.length : 0

    let result  = ''
    let counter = 0

    if (words > maxWords) {
        event.preventDefault()
        field.value.split(' ').forEach((value, index) => {
            if (counter < maxWords) {
                result += value + ' '
                counter++
            }
        })
        field.value = result.trimRight().substring(0, field.value.length - 1)
    }

    let recount = field.value.match(/\S+/g)
    recount     = recount ? recount.length : '0'

    counterContainer.innerHTML = recount + ' de ' + maxWords + ' palavras'
}

export function writeSlugOnTargetElement(source, target) {
    if (source && target) {
        typeWriter(str2Slug(source.value), target)
        return target.focus()
    }

    return false
}

export function stringToJSON(string) {
    if (string.length >= 1) {
        string = string.replace(/(\w+:)|(\w+ :)/g, function (matchedStr) {
            return '"' + matchedStr.substring(0, matchedStr.length - 1) + '":'
        })
        return JSON.parse(string)
    }
}

export function str2Slug(s, options) {
    s       = String(s)
    options = Object(options)

    let defaults = {
        'delimiter'    : '-',
        'limit'        : undefined,
        'lowercase'    : true,
        'replacements' : {},
        'transliterate': (typeof (XRegExp) === 'undefined'),
    }

    // Merge options
    for (let k in defaults)
        if (!options.hasOwnProperty(k))
            options[k] = defaults[k]

    let char_map = {
        // Latin
        'À': 'A', 'Á': 'A', 'Â': 'A', 'Ã': 'A', 'Ä': 'A', 'Å': 'A', 'Æ': 'AE', 'Ç': 'C',
        'È': 'E', 'É': 'E', 'Ê': 'E', 'Ë': 'E', 'Ì': 'I', 'Í': 'I', 'Î': 'I', 'Ï': 'I',
        'Ð': 'D', 'Ñ': 'N', 'Ò': 'O', 'Ó': 'O', 'Ô': 'O', 'Õ': 'O', 'Ö': 'O', 'Ő': 'O',
        'Ø': 'O', 'Ù': 'U', 'Ú': 'U', 'Û': 'U', 'Ü': 'U', 'Ű': 'U', 'Ý': 'Y', 'Þ': 'TH',
        'ß': 'ss',
        'à': 'a', 'á': 'a', 'â': 'a', 'ã': 'a', 'ä': 'a', 'å': 'a', 'æ': 'ae', 'ç': 'c',
        'è': 'e', 'é': 'e', 'ê': 'e', 'ë': 'e', 'ì': 'i', 'í': 'i', 'î': 'i', 'ï': 'i',
        'ð': 'd', 'ñ': 'n', 'ò': 'o', 'ó': 'o', 'ô': 'o', 'õ': 'o', 'ö': 'o', 'ő': 'o',
        'ø': 'o', 'ù': 'u', 'ú': 'u', 'û': 'u', 'ü': 'u', 'ű': 'u', 'ý': 'y', 'þ': 'th',
        'ÿ': 'y',

        // Latin symbols
        '©': '(c)',

        // Russian
        'А': 'A', 'Б': 'B', 'В': 'V', 'Г': 'G', 'Д': 'D', 'Е': 'E', 'Ё': 'Yo', 'Ж': 'Zh',
        'З': 'Z', 'И': 'I', 'Й': 'J', 'К': 'K', 'Л': 'L', 'М': 'M', 'Н': 'N', 'О': 'O',
        'П': 'P', 'Р': 'R', 'С': 'S', 'Т': 'T', 'У': 'U', 'Ф': 'F', 'Х': 'H', 'Ц': 'C',
        'Ч': 'Ch', 'Ш': 'Sh', 'Щ': 'Sh', 'Ъ': '', 'Ы': 'Y', 'Ь': '', 'Э': 'E', 'Ю': 'Yu',
        'Я': 'Ya',
        'а': 'a', 'б': 'b', 'в': 'v', 'г': 'g', 'д': 'd', 'е': 'e', 'ё': 'yo', 'ж': 'zh',
        'з': 'z', 'и': 'i', 'й': 'j', 'к': 'k', 'л': 'l', 'м': 'm', 'н': 'n', 'о': 'o',
        'п': 'p', 'р': 'r', 'с': 's', 'т': 't', 'у': 'u', 'ф': 'f', 'х': 'h', 'ц': 'c',
        'ч': 'ch', 'ш': 'sh', 'щ': 'sh', 'ъ': '', 'ы': 'y', 'ь': '', 'э': 'e', 'ю': 'yu',
        'я': 'ya',
    }

    // Make custom replacements
    for (let k in options.replacements) {
        s = s.replace(RegExp(k, 'g'), options.replacements[k])
    }

    // Transliterate characters to ASCII
    if (options.transliterate) {
        for (let k in char_map) {
            s = s.replace(RegExp(k, 'g'), char_map[k])
        }
    }

    // Replace non-alphanumeric characters with our delimiter
    let alphaNumeric = (typeof (XRegExp) === 'undefined') ? RegExp('[^a-z0-9]+', 'ig') : XRegExp('[^\\p{L}\\p{N}]+', 'ig')

    s = s.replace(alphaNumeric, options.delimiter)

    // Remove duplicate delimiters
    s = s.replace(RegExp('[' + options.delimiter + ']{2,}', 'g'), options.delimiter)

    // Truncate slug to max. characters
    s = s.substring(0, options.limit)

    // Remove delimiter from ends
    s = s.replace(RegExp('(^' + options.delimiter + '|' + options.delimiter + '$)', 'g'), '')

    return options.lowercase ? s.toLowerCase() : s
}

export function typeWriter(text, targetElement, i) {
    i = i || 0

    if (targetElement && i === 0) {
        targetElement.value     = ''
        targetElement.innerHTML = ''
    }

    if (targetElement && (i < text.length)) {
        targetElement.value += text.charAt(i)
        targetElement.innerHTML += text.charAt(i)
        i++

        setTimeout(() => {
            typeWriter(text, targetElement, i)
        }, 30)
    }
}
