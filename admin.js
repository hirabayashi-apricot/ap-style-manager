document.addEventListener("DOMContentLoaded", function () {

	const editorStyles    = document.getElementById("apsm-editor-styles")
	const editorBlocks    = document.getElementById("apsm-editor-blocks")
	const jsonField       = document.getElementById("apsm-json")
	const addStyle        = document.getElementById("add-style")
	const addBlock        = document.getElementById("add-block")
	const postTypeSelect  = document.getElementById("apsm-posttype")
	const form            = document.querySelector("form")
	const validationMessage = document.getElementById("apsm-validation")

	/* ------------------------
	JSON
	------------------------ */

	function parseJson(value){
		try{
			return JSON.parse(value || "{}")
		}catch(e){
			return {}
		}
	}

	function normalizeStyles(styles){

		if(!Array.isArray(styles)) return []

		const normalized = []

		styles.forEach(style=>{

			if(!style || typeof style !== "object") return

			if(Array.isArray(style.items)){

				style.items.forEach(item=>{
					if(item && typeof item === "object"){
						normalized.push(item)
					}
				})

				return
			}

			normalized.push(style)

		})

		return normalized

	}

	function escapeAttr(value){

		return String(value || "")
		.replace(/&/g,"&amp;")
		.replace(/</g,"&lt;")
		.replace(/>/g,"&gt;")
		.replace(/"/g,"&quot;")

	}

	let data = parseJson(jsonField.value)

	let currentPostType = postTypeSelect.value || "base"

	/* ------------------------
	block判定タグ
	------------------------ */

	const blockTags = [
		"p","div",
		"h1","h2","h3","h4","h5","h6",
		"blockquote",
		"ul","ol","li",
		"table",
		"section","article","figure"
	]

	function normalizeClassValue(value){
		return String(value || "")
			.replace(/\s+/g," ")
			.trim()
	}

	function isValidTagName(tag){
		return /^[a-z][a-z0-9-]*$/.test(tag)
	}

	function isValidClassList(className){
		return /^([A-Za-z0-9_-]+)(\s+[A-Za-z0-9_-]+)*$/.test(className)
	}

	function clearValidation(){

		document.querySelectorAll(".apsm-item").forEach(item=>{
			item.classList.remove("is-error")
		})

		if(validationMessage){
			validationMessage.textContent = ""
			validationMessage.hidden = true
		}

	}

	function showValidation(messages){

		if(!validationMessage){
			return
		}

		validationMessage.innerHTML = messages
			.map(message=>`<span>${escapeAttr(message)}</span>`)
			.join("<br>")

		validationMessage.hidden = false

	}

	function validateItems(showError = false){

		const errors = []

		document.querySelectorAll(".apsm-item").forEach((item,index)=>{

			item.classList.remove("is-error")

			const tag = item.querySelector(".item-tag").value.trim().toLowerCase()
			const cls = normalizeClassValue(item.querySelector(".item-class").value)
			const title = item.querySelector(".item-title").value.trim()

			if(!title && !tag && !cls){
				return
			}

			const rowErrors = []

			if(!title){
				rowErrors.push("タイトルは必須")
			}

			if(!tag){
				rowErrors.push("タグは必須")
			}else if(!isValidTagName(tag)){
				rowErrors.push("タグは半角英小文字・数字・ハイフンのみ")
			}

			if(cls && !isValidClassList(cls)){
				rowErrors.push("classは半角英数字・ハイフン・アンダースコア・スペースのみ")
			}

			if(rowErrors.length){
				item.classList.add("is-error")
				errors.push(`${index + 1}行目: ${rowErrors.join(" / ")}`)
			}

		})

		if(showError){
			if(errors.length){
				showValidation(errors)
			}else{
				clearValidation()
			}
		}

		return errors.length === 0

	}

	/* ------------------------
	item
	------------------------ */

	function itemTemplate(d = {}){

		const tag = d.block || d.inline || ""

		const el = document.createElement("div")

		el.className = "apsm-item"

		el.innerHTML = `
			<input class="item-title" placeholder="タイトル" value="${escapeAttr(d.title)}">
			<input class="item-tag" placeholder="タグ (h2 / span など)" value="${escapeAttr(tag)}">
			<input class="item-class" placeholder="class" value="${escapeAttr((d.attributes && d.attributes.class) || "")}">
			<button type="button" class="move-up button" aria-label="1つ上へ">↑</button>
			<button type="button" class="move-down button" aria-label="1つ下へ">↓</button>
			<button type="button" class="remove-item button">削除</button>
		`

		el.querySelector(".remove-item").onclick = () => {
			el.remove()
			clearValidation()
		}

		/* 並べ替え（1.1.0でSortableJSのドラッグから置き換え） */
		el.querySelector(".move-up").onclick = function(){
			const prev = el.previousElementSibling
			if(!prev) return
			el.parentNode.insertBefore(el, prev)
			this.focus()
			saveTemp()
		}

		el.querySelector(".move-down").onclick = function(){
			const next = el.nextElementSibling
			if(!next) return
			el.parentNode.insertBefore(next, el)
			this.focus()
			saveTemp()
		}

		return el

	}

	/* ------------------------
	post type保証
	------------------------ */

	function ensurePostType(postType){

		if(!data[postType] || typeof data[postType] !== "object" || Array.isArray(data[postType])){
			data[postType] = { styles: [], blocks: [] }
		}

		if(!Array.isArray(data[postType].styles)){
			data[postType].styles = []
		}

		if(!Array.isArray(data[postType].blocks)){
			data[postType].blocks = []
		}

		data[postType].styles = normalizeStyles(data[postType].styles)
		data[postType].blocks = normalizeStyles(data[postType].blocks)

	}

	/* ------------------------
	add（load より先に登録）
	------------------------ */

	addStyle.onclick = function(e){
		e.preventDefault()
		editorStyles.appendChild(itemTemplate())
		clearValidation()
	}

	addBlock.onclick = function(e){
		e.preventDefault()
		editorBlocks.appendChild(itemTemplate())
		clearValidation()
	}

	/* ------------------------
	load
	------------------------ */

	function load(postType){

		editorStyles.innerHTML = ""
		editorBlocks.innerHTML = ""

		ensurePostType(postType)

		data[postType].styles.forEach(item=>{
			editorStyles.appendChild(itemTemplate(item))
		})

		data[postType].blocks.forEach(item=>{
			editorBlocks.appendChild(itemTemplate(item))
		})

		clearValidation()

	}

	jsonField.value = JSON.stringify(data,null,2)

	load(currentPostType)

	/* ------------------------
	post type switch
	------------------------ */

	postTypeSelect.addEventListener("change",function(){

		const nextPostType = this.value

		const isSaved = saveTemp({
			showError:true
		})

		if(!isSaved){
			this.value = currentPostType
			return
		}

		currentPostType = nextPostType

		load(currentPostType)

	})

	/* ------------------------
	save
	------------------------ */

	function collectItems(editorEl){

		let items = []

		editorEl.querySelectorAll(".apsm-item").forEach(i=>{

			let tag   = i.querySelector(".item-tag").value.trim().toLowerCase()
			let cls   = normalizeClassValue(i.querySelector(".item-class").value)
			let title = i.querySelector(".item-title").value.trim()

			if(!title && !tag && !cls){
				return
			}

			let item = { title: title }

			if(tag){
				if(blockTags.includes(tag)){
					item.block = tag
				}else{
					item.inline = tag
				}
			}

			item.attributes = { class: cls || "" }

			items.push(item)

		})

		return items

	}

	function saveTemp(options = {}){

		const showError = options.showError === true

		const isValid = validateItems(showError)

		if(!isValid){
			return false
		}

		data[currentPostType].styles = collectItems(editorStyles)
		data[currentPostType].blocks = collectItems(editorBlocks)

		jsonField.value = JSON.stringify(data,null,2)

		return true

	}

	document.querySelectorAll(".apsm-editor").forEach(ed=>{
		ed.addEventListener("input",function(){
			clearValidation()
		})
	})

	form.addEventListener("submit",function(e){
		const isSaved = saveTemp({
			showError:true
		})
		if(!isSaved){
			e.preventDefault()
		}
	})

})
