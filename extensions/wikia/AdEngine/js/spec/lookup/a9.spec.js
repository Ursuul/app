/*global describe, it, modules, expect, spyOn*/
describe('Method ext.wikia.adEngine.lookup.a9', function () {
	'use strict';

	var mocks,
		testCases,
		VIDEO_SLOT_NAME = 'FEATURED',
		videoBidReponse = {
			amznbid: 'testBidId',
			amzniid: 'testImpression-123',
			amznp: 'testP',
			encodedQsParams: '%26amzniid%3DtestImpression-1028258831504272828705%26amznbid%3Dv_testBid%26amznp%3DtestP',
			mediaType: 'video',
			qsParams: '&amzniid=testImpression-1028258831504272828705&amznbid=v_testBid&amznp=testP',
			slotID: 'FEATURED'
		};

	function noop() {
		return;
	}

	function getFactory() {
		return modules['ext.wikia.adEngine.lookup.lookupFactory'](
			mocks.adContext,
			mocks.adTracker,
			mocks.sourcePoint,
			mocks.lazyQueue,
			mocks.log
		);
	}

	function getModule() {
		return modules['ext.wikia.adEngine.lookup.a9'](
			mocks.adContext,
			mocks.slotsContext,
			getFactory(),
			mocks.document,
			mocks.log,
			mocks.window
		);
	}

	function init(a9, bids) {
		mocks.window.apstag.fetchBids = function (config, callback) {
			callback(bids);
		};
		a9.call();
	}

	mocks = {
		targeting: noop,
		adContext: {
			getContext: function () {
				return {
					opts: noop,
					slots: noop,
					targeting: mocks.targeting
				};
			}
		},
		slotsContext: {
			filterSlotMap: function (map) {
				return map;
			}
		},
		adTracker: {
			measureTime: function () {
				return {
					measureDiff: function () {
						return {
							track: noop
						};
					},
					track: noop
				};
			},
			track: noop
		},
		sourcePoint: {
			addOnBlockingCallback: noop
		},
		document: {
			createElement: function () {
				return {
					addEventListener: function (eventName, callback) {
						callback();
					}
				};
			},
			getElementsByTagName: function () {
				return [
					{
						parentNode: {
							insertBefore: noop
						}
					}
				];
			}
		},
		lazyQueue: {
			makeQueue: function (queue, callback) {
				queue.push = function () {
					callback();
				};
				queue.start = noop;
			}
		},
		log: noop,
		window: {
			apstag: {
				getAdsCallback: function (id, callback) {
					callback();
				},
				renderAd: noop,
				getTokens: noop
			}
		}
	};

	testCases = [
		// Empty
		{
			skin: 'oasis', input: [], expected: {}
		},
		// Single slot
		{
			skin: 'oasis', input: [
			{
				slotID: 'TOP_LEADERBOARD',
				amznbid: 'amznbid',
				amzniid: 'amzniid',
				amznsz: '728x90',
				amznp: 'amznp'
			}
		],
			expected: {
				leaderboard: {
					amznbid: 'amznbid',
					amzniid: 'amzniid',
					amznsz: '728x90',
					amznp: 'amznp'
				}
			}
		},
		// multi slot
		{
			skin: 'oasis', input: [
			{
				slotID: 'TOP_LEADERBOARD',
				amznbid: 'amznbid',
				amzniid: 'amzniid',
				amznsz: '728x90',
				amznp: 'amznp'
			},
			{
				slotID: 'TOP_RIGHT_BOXAD',
				amznbid: 'amznbid2',
				amzniid: 'amzniid2',
				amznsz: '300x250',
				amznp: 'amznp2'
			},
			{
				slotID: 'INCONTENT_BOXAD_1',
				amznbid: 'amznbid3',
				amzniid: 'amzniid3',
				amznsz: '300x600',
				amznp: 'amznp3'
			}
		],
			expected: {
				leaderboard: {
					amznbid: 'amznbid',
					amzniid: 'amzniid',
					amznsz: '728x90',
					amznp: 'amznp'
				},
				medrec: {
					amznbid: 'amznbid2',
					amzniid: 'amzniid2',
					amznsz: '300x250',
					amznp: 'amznp2'
				},
				incontentBoxad: {
					amznbid: 'amznbid3',
					amzniid: 'amzniid3',
					amznsz: '300x600',
					amznp: 'amznp3'
				}
			}
		}
	];

	Object.keys(testCases).forEach(function (k) {
		it('calculate params for A9 slots #' + k, function () {
			var a9 = getModule(),
				testCase = testCases[k];

			mocks.targeting.skin = testCase.skin;

			init(a9, testCase.input);

			expect(a9.getSlotParams('TOP_LEADERBOARD')).toEqual(testCase.expected.leaderboard || {});
			expect(a9.getSlotParams('TOP_RIGHT_BOXAD')).toEqual(testCase.expected.medrec || {});
			expect(a9.getSlotParams('INCONTENT_BOXAD_1')).toEqual(testCase.expected.incontentBoxad || {});

			expect(a9.getSlotParams('MOBILE_TOP_LEADERBOARD')).toEqual(testCase.expected.mobileleaderboard || {});
			expect(a9.getSlotParams('MOBILE_IN_CONTENT')).toEqual(testCase.expected.mobileincontent || {});
			expect(a9.getSlotParams('MOBILE_PREFOOTER')).toEqual(testCase.expected.mobileprefooter || {});
		});
	});

	it('switch the flag when response from A9 recieved', function () {
		var a9 = getModule();
		mocks.targeting.skin = 'oasis';
		init(a9, []);
		expect(a9.hasResponse()).toEqual(true);
	});

	it('should not show video slot if it is not enabled', function () {
		expect(getModule().getSlotParams(VIDEO_SLOT_NAME)).toEqual({});
	});

	it('should get video slot if it is enabled', function () {
		spyOn(mocks.adContext, 'getContext').and.returnValue({targeting: {skin: 'oasis'}, bidders: {a9Video: true}});
		var a9 = getModule(),
			slotParams;

		init(a9, [videoBidReponse]);

		slotParams = a9.getSlotParams(VIDEO_SLOT_NAME);
		expect(slotParams.amznbid).toEqual(videoBidReponse.amznbid);
		expect(slotParams.amzniid).toEqual(videoBidReponse.amzniid);
		expect(slotParams.amznp).toEqual(videoBidReponse.amznp);
	});
});
