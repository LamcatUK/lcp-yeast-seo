(function (wp) {
  if (!wp || !wp.editPost || !wp.plugins || !window.lcpYeastSeoEditor) {
    return;
  }

  var el = wp.element.createElement;
  var Fragment = wp.element.Fragment;
  var useState = wp.element.useState;
  var useEffect = wp.element.useEffect;
  var apiFetch = wp.apiFetch;
  var __ = wp.i18n.__;
  var registerPlugin = wp.plugins.registerPlugin;
  var PluginMoreMenuItem = wp.editPost.PluginMoreMenuItem;
  var PluginPostStatusInfo = wp.editPost.PluginPostStatusInfo;
  var Button = wp.components.Button;
  var Modal = wp.components.Modal;
  var Notice = wp.components.Notice;
  var PanelBody = wp.components.PanelBody;
  var SelectControl = wp.components.SelectControl;
  var TextControl = wp.components.TextControl;
  var TextareaControl = wp.components.TextareaControl;
  var useSelect = wp.data.useSelect;
  var useDispatch = wp.data.useDispatch;
  var settings = window.lcpYeastSeoEditor;

  var NO_DUPES = { title: [], description: [] };

  function DuplicateNote(props) {
    if (!props.items || !props.items.length) {
      return null;
    }

    var links = [];

    props.items.forEach(function (item, index) {
      if (index) {
        links.push(", ");
      }

      links.push(
        el(
          "a",
          {
            key: item.id,
            href: item.link,
            target: "_blank",
            rel: "noopener noreferrer",
          },
          item.title || __("(no title)", "lcp-yeast-seo"),
        ),
      );
    });

    return el("p", { className: "lcp-yeast-seo-note" }, props.label + " ", links);
  }

  function countText(value) {
    return (value || "").trim().length;
  }

  function guidance(count, min, max) {
    if (0 === count) {
      return __(
        "Empty - plugin falls back to the default output.",
        "lcp-yeast-seo",
      );
    }

    if (count < min) {
      return __("A bit short.", "lcp-yeast-seo");
    }

    if (count > max) {
      return __("Too long - may be cut off in search results.", "lcp-yeast-seo");
    }

    return __("Good length.", "lcp-yeast-seo");
  }

  function countClass(count, min, max) {
    if (count > max) {
      return "lcp-yeast-seo-count lcp-yeast-seo-count--long";
    }

    if (count > 0 && count < min) {
      return "lcp-yeast-seo-count lcp-yeast-seo-count--short";
    }

    return "lcp-yeast-seo-count";
  }

  function previewUrl(permalink, slug) {
    if (permalink) {
      return permalink;
    }

    if (slug) {
      return (
        settings.homeUrl.replace(/\/$/, "") +
        "/" +
        slug.replace(/^\//, "") +
        "/"
      );
    }

    return settings.homeUrl;
  }

  function openMediaFrame(onSelect) {
    if (!wp.media) {
      return;
    }

    var frame = wp.media({
      title: __("Choose image", "lcp-yeast-seo"),
      multiple: false,
      library: {
        type: "image",
      },
    });

    frame.on("select", function () {
      var selection = frame.state().get("selection").first();

      if (!selection) {
        return;
      }

      var image = selection.toJSON();
      onSelect(image.url || "");
    });

    frame.open();
  }

  function ImageField(props) {
    var shown = props.value || props.fallbackUrl || "";
    var usingFallback = !props.value && !!props.fallbackUrl;

    return el(
      wp.components.BaseControl,
      {
        label: props.label,
        help: props.help,
        className: "lcp-yeast-seo-image-field",
        __nextHasNoMarginBottom: true,
      },
      shown &&
        el("img", {
          className:
            "lcp-yeast-seo-image-preview" +
            (usingFallback ? " lcp-yeast-seo-image-preview--fallback" : ""),
          src: shown,
          alt: "",
        }),
      usingFallback &&
        el(
          "p",
          { className: "lcp-yeast-seo-note lcp-yeast-seo-note--muted" },
          props.fallbackLabel,
        ),
      el(
        "div",
        { className: "lcp-yeast-seo-image-actions" },
        el(
          Button,
          {
            variant: "secondary",
            onClick: function () {
              openMediaFrame(props.onChange);
            },
          },
          props.value
            ? __("Change image", "lcp-yeast-seo")
            : __("Choose image", "lcp-yeast-seo"),
        ),
        props.value &&
          el(
            Button,
            {
              variant: "tertiary",
              isDestructive: true,
              onClick: function () {
                props.onChange("");
              },
            },
            __("Remove", "lcp-yeast-seo"),
          ),
      ),
    );
  }

  function YeastSeoModal() {
    var modalState = useState(false);
    var isOpen = modalState[0];
    var setIsOpen = modalState[1];
    var searchState = useState(true);
    var isSearchOpen = searchState[0];
    var setIsSearchOpen = searchState[1];
    var socialState = useState(false);
    var isSocialOpen = socialState[0];
    var setIsSocialOpen = socialState[1];

    var postData = useSelect(function (select) {
      var editor = select("core/editor");
      var currentPost = editor.getCurrentPost();
      var featuredId = editor.getEditedPostAttribute("featured_media") || 0;
      var featured = featuredId ? select("core").getMedia(featuredId) : null;

      return {
        meta: editor.getEditedPostAttribute("meta") || {},
        postTitle: editor.getEditedPostAttribute("title") || "",
        slug: editor.getEditedPostAttribute("slug") || "",
        permalink: currentPost && currentPost.link ? currentPost.link : "",
        postId: editor.getCurrentPostId(),
        featuredMedia: featuredId,
        featuredUrl: featured && featured.source_url ? featured.source_url : "",
      };
    }, []);

    var dupeState = useState(NO_DUPES);
    var dupes = dupeState[0];
    var setDupes = dupeState[1];

    var editPost = useDispatch("core/editor").editPost;

    function updateMeta(key, value) {
      var nextMeta = Object.assign({}, postData.meta);
      nextMeta[key] = value;
      editPost({ meta: nextMeta });
    }

    function openModal() {
      setIsOpen(true);
    }

    var title = postData.meta[settings.metaKeys.title] || "";
    var description = postData.meta[settings.metaKeys.description] || "";
    var ogTitle = postData.meta[settings.metaKeys.ogTitle] || "";
    var ogDescription = postData.meta[settings.metaKeys.ogDescription] || "";
    var ogImage = postData.meta[settings.metaKeys.ogImage] || "";
    var twitterImage = postData.meta[settings.metaKeys.twitterImage] || "";
    var robotsIndex = postData.meta[settings.metaKeys.robotsIndex] || "index";
    var canonicalUrl = postData.meta[settings.metaKeys.canonicalUrl] || "";
    var schema = postData.meta[settings.metaKeys.schema] || "";
    var titleCount = countText(title);

    useEffect(
      function () {
        var query = (title || "").trim() + (description || "").trim();

        if (!isOpen || !apiFetch || !query) {
          setDupes(NO_DUPES);
          return undefined;
        }

        var cancelled = false;
        var timer = setTimeout(function () {
          apiFetch({
            path:
              "/lcp-yeast-seo/v1/duplicates?" +
              new URLSearchParams({
                post_id: postData.postId || 0,
                title: title,
                description: description,
              }).toString(),
          })
            .then(function (result) {
              if (!cancelled) {
                setDupes(result);
              }
            })
            .catch(function () {});
        }, 700);

        return function () {
          cancelled = true;
          clearTimeout(timer);
        };
      },
      [isOpen, title, description, postData.postId],
    );
    var descriptionCount = countText(description);
    var resolvedUrl = previewUrl(postData.permalink, postData.slug);
    var previewTitle =
      title || postData.postTitle || __("(No title yet)", "lcp-yeast-seo");
    var previewDescription =
      description ||
      __(
        settings.autoDescription
          ? "No description set. The page excerpt, or the start of its content, will be used."
          : "Your meta description will appear here when set. Leave it blank to keep the default output.",
        "lcp-yeast-seo",
      );
    var previewOgTitle = ogTitle || previewTitle;
    var previewOgDescription = ogDescription || previewDescription;
    var previewImage =
      twitterImage ||
      ogImage ||
      postData.featuredUrl ||
      settings.defaultSocialImage ||
      "";
    var ogFallback = postData.featuredUrl
      ? {
          url: postData.featuredUrl,
          label: __(
            "Using the featured image. Choose an image here to override it.",
            "lcp-yeast-seo",
          ),
        }
      : settings.defaultSocialImage
        ? {
            url: settings.defaultSocialImage,
            label: __("Using the site default image.", "lcp-yeast-seo"),
          }
        : { url: "", label: "" };

    return el(
      Fragment,
      null,
      el(
        PluginMoreMenuItem,
        {
          icon: "search",
          onClick: openModal,
        },
        __("Yeast SEO", "lcp-yeast-seo"),
      ),
      PluginPostStatusInfo &&
        el(
          PluginPostStatusInfo,
          null,
          el(
            "div",
            { className: "lcp-yeast-seo-status-row" },
            el(
              Button,
              {
                variant: "primary",
                size: "compact",
                className: "lcp-yeast-seo-status-button",
                onClick: openModal,
              },
              __("Yeast SEO", "lcp-yeast-seo"),
            ),
          ),
        ),
      isOpen &&
        el(
          Modal,
          {
            title: __("Yeast SEO", "lcp-yeast-seo"),
            className: "lcp-yeast-seo-modal",
            onRequestClose: function () {
              setIsOpen(false);
            },
          },
          el(
            "div",
            { className: "lcp-yeast-seo-layout" },
            el(
              "div",
              { className: "lcp-yeast-seo-main" },
              !settings.blogPublic &&
                el(
                  Notice,
                  { status: "warning", isDismissible: false },
                  __(
                    "WordPress is set to discourage search engines.",
                    "lcp-yeast-seo",
                  ),
                ),
              el(
                PanelBody,
                {
                  title: __("Search appearance", "lcp-yeast-seo"),
                  initialOpen: true,
                  opened: isSearchOpen,
                  onToggle: function () {
                    setIsSearchOpen(!isSearchOpen);
                  },
                },
                el(TextControl, {
                  label: __("Page title", "lcp-yeast-seo"),
                  value: title,
                  onChange: function (value) {
                    updateMeta(settings.metaKeys.title, value);
                  },
                  help: __(
                    settings.yoastActive
                      ? "Overrides the browser title and Yoast title for this page only."
                      : "Overrides the browser title for this page only.",
                    "lcp-yeast-seo",
                  ),
                }),
                el(
                  "p",
                  { className: countClass(titleCount, 30, 60) },
                  titleCount + " characters - " + guidance(titleCount, 30, 60),
                ),
                el(DuplicateNote, {
                  items: dupes.title,
                  label: __("Same title is also used on:", "lcp-yeast-seo"),
                }),
                el(TextareaControl, {
                  label: __("Meta description", "lcp-yeast-seo"),
                  value: description,
                  onChange: function (value) {
                    updateMeta(settings.metaKeys.description, value);
                  },
                  help: __(
                    "Used for the page meta description. Leave blank to keep the default output.",
                    "lcp-yeast-seo",
                  ),
                  rows: 5,
                }),
                el(
                  "p",
                  { className: countClass(descriptionCount, 70, 160) },
                  descriptionCount +
                    " characters - " +
                    guidance(descriptionCount, 70, 160),
                ),
                el(DuplicateNote, {
                  items: dupes.description,
                  label: __("Same description is also used on:", "lcp-yeast-seo"),
                }),
                el(SelectControl, {
                  label: __("Indexing", "lcp-yeast-seo"),
                  value: robotsIndex,
                  onChange: function (value) {
                    updateMeta(settings.metaKeys.robotsIndex, value);
                  },
                  help: __(
                    "Defaults to index. The global WordPress privacy setting can still force noindex.",
                    "lcp-yeast-seo",
                  ),
                  options: [
                    { label: __("Index", "lcp-yeast-seo"), value: "index" },
                    { label: __("Noindex", "lcp-yeast-seo"), value: "noindex" },
                  ],
                }),
                el(TextControl, {
                  label: __("Canonical URL", "lcp-yeast-seo"),
                  type: "url",
                  value: canonicalUrl,
                  onChange: function (value) {
                    updateMeta(settings.metaKeys.canonicalUrl, value);
                  },
                  help: __(
                    "Optional. Overrides the canonical link tag for this page only. Leave blank to keep the default (this page's own URL).",
                    "lcp-yeast-seo",
                  ),
                }),
              ),
              settings.schemaEnabled &&
                el(
                  PanelBody,
                  {
                    title: __("Schema (JSON-LD)", "lcp-yeast-seo"),
                    initialOpen: false,
                  },
                  el(TextareaControl, {
                    label: __("Schema markup", "lcp-yeast-seo"),
                    value: schema,
                    onChange: function (value) {
                      updateMeta(settings.metaKeys.schema, value);
                    },
                    help: __(
                      "One JSON object, or an array/@graph of objects. Saving with a problem is allowed; check the post-save notice.",
                      "lcp-yeast-seo",
                    ),
                    rows: 16,
                    className: "lcp-yeast-seo-schema-field",
                  }),
                ),
              el(
                PanelBody,
                {
                  title: __("Social sharing", "lcp-yeast-seo"),
                  initialOpen: false,
                  opened: isSocialOpen,
                  onToggle: function () {
                    setIsSocialOpen(!isSocialOpen);
                  },
                },
                el(TextControl, {
                  label: __("Open Graph title", "lcp-yeast-seo"),
                  value: ogTitle,
                  onChange: function (value) {
                    updateMeta(settings.metaKeys.ogTitle, value);
                  },
                  help: __(
                    "Optional override for link previews. Falls back to the page title.",
                    "lcp-yeast-seo",
                  ),
                }),
                el(TextareaControl, {
                  label: __("Open Graph description", "lcp-yeast-seo"),
                  value: ogDescription,
                  onChange: function (value) {
                    updateMeta(settings.metaKeys.ogDescription, value);
                  },
                  help: __(
                    "Optional override for social snippets. Falls back to the meta description.",
                    "lcp-yeast-seo",
                  ),
                  rows: 4,
                }),
                el(ImageField, {
                  label: __("Open Graph image", "lcp-yeast-seo"),
                  value: ogImage,
                  onChange: function (value) {
                    updateMeta(settings.metaKeys.ogImage, value);
                  },
                  fallbackUrl: ogFallback.url,
                  fallbackLabel: ogFallback.label,
                  help: __(
                    "Optional link-preview image. If empty, the featured image is used, then the first image in the content, then the site default image.",
                    "lcp-yeast-seo",
                  ),
                }),
                !ogImage &&
                  !postData.featuredMedia &&
                  !settings.defaultSocialImage &&
                  el(
                    "p",
                    { className: "lcp-yeast-seo-note lcp-yeast-seo-note--muted" },
                    __(
                      "No image is set here, as the featured image, or as the site default. Link previews will use the first image in the content, if there is one.",
                      "lcp-yeast-seo",
                    ),
                  ),
                el(ImageField, {
                  label: __("Twitter/X image", "lcp-yeast-seo"),
                  value: twitterImage,
                  onChange: function (value) {
                    updateMeta(settings.metaKeys.twitterImage, value);
                  },
                  fallbackUrl: ogImage || ogFallback.url,
                  fallbackLabel: ogImage
                    ? __("Using the Open Graph image.", "lcp-yeast-seo")
                    : ogFallback.label,
                  help: __(
                    "Optional Twitter/X-specific image. If empty, the Open Graph image is used.",
                    "lcp-yeast-seo",
                  ),
                }),
              ),
            ),
            el(
              "aside",
              { className: "lcp-yeast-seo-preview" },
              isSearchOpen &&
                el(
                  Fragment,
                  null,
                  el(
                    "p",
                    { className: "lcp-yeast-seo-preview-label" },
                    __("Search preview", "lcp-yeast-seo"),
                  ),
                  el(
                    "div",
                    { className: "lcp-yeast-seo-serp" },
                    el(
                      "div",
                      { className: "lcp-yeast-seo-serp-url" },
                      resolvedUrl,
                    ),
                    el(
                      "div",
                      { className: "lcp-yeast-seo-serp-title" },
                      previewTitle,
                    ),
                    el(
                      "div",
                      { className: "lcp-yeast-seo-serp-description" },
                      previewDescription,
                    ),
                  ),
                ),
              isSocialOpen &&
                el(
                  Fragment,
                  null,
                  el(
                    "p",
                    { className: "lcp-yeast-seo-preview-label" },
                    __("Social preview", "lcp-yeast-seo"),
                  ),
                  el(
                    "div",
                    { className: "lcp-yeast-seo-social-card" },
                    previewImage &&
                      el("img", {
                        className: "lcp-yeast-seo-social-image",
                        src: previewImage,
                        alt: "",
                      }),
                    el(
                      "div",
                      { className: "lcp-yeast-seo-social-copy" },
                      el(
                        "div",
                        { className: "lcp-yeast-seo-social-site" },
                        resolvedUrl,
                      ),
                      el(
                        "div",
                        { className: "lcp-yeast-seo-social-title" },
                        previewOgTitle,
                      ),
                      el(
                        "div",
                        { className: "lcp-yeast-seo-social-description" },
                        previewOgDescription,
                      ),
                    ),
                  ),
                ),
              el(
                "div",
                { className: "lcp-yeast-seo-guidance" },
                el(
                  "p",
                  null,
                  __(
                    "Targets: title 30-60 characters, description 70-160 characters.",
                    "lcp-yeast-seo",
                  ),
                ),
                el(
                  "p",
                  null,
                  __(
                    "Leave title and description blank to keep the normal page output; leave social image fields blank to fall back to the site-wide default image.",
                    "lcp-yeast-seo",
                  ),
                ),
              ),
              el(
                Button,
                {
                  variant: "secondary",
                  onClick: function () {
                    setIsOpen(false);
                  },
                },
                __("Close", "lcp-yeast-seo"),
              ),
            ),
          ),
        ),
    );
  }

  registerPlugin("lcp-yeast-seo", {
    render: YeastSeoModal,
    icon: "search",
  });
})(window.wp);
