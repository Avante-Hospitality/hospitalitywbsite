module.exports = function (eleventyConfig) {
  // img/ and styles.css stay at the repo root (assets are added by hand);
  // only the page templates live in src/.
  eleventyConfig.addPassthroughCopy("img");
  eleventyConfig.addPassthroughCopy("styles.css");

  return {
    dir: {
      input: "src",
      output: "_site",
      includes: "_includes",
      data: "_data",
    },
    htmlTemplateEngine: "njk",
  };
};
