import test from 'node:test';
import assert from 'node:assert/strict';
import { createSharedUiBridge } from '../shared-ui-bridge.mjs';

test('shared backend receives each page once, then completion', async () => {
 const events=[];const page={};
 const bridge=createSharedUiBridge(async()=>({track:async p=>events.push(p),finish:async o=>events.push(o)}));
 bridge.track(page);bridge.track(page);
 assert.equal(await bridge.finish({settled:true,humanChallenge:false}),true);
 assert.deepEqual(events,[page,{settled:true,humanChallenge:false}]);
});
test('no owned page means no backend work', async () => {
 let loads=0;const bridge=createSharedUiBridge(async()=>{loads++;return null;});
 assert.equal(await bridge.finish({settled:true}),false);assert.equal(loads,0);
});
test('missing or failed backend retains existing safe cleanup fallback', async () => {
 for(const factory of [async()=>null,async()=>{throw Error('unavailable')},async()=>({track:async()=>{throw Error('partial')},finish:async()=>{}})]){
  const bridge=createSharedUiBridge(factory);bridge.track({});
  assert.equal(await bridge.finish({settled:true}),false);
 }
});
test('interruption and human verification reach backend without claiming completion', async () => {
 let seen;const bridge=createSharedUiBridge(async()=>({track:async()=>{},finish:async o=>{seen=o;}}));
 bridge.track({});assert.equal(await bridge.finish({settled:false,humanChallenge:true}),true);
 assert.deepEqual(seen,{settled:false,humanChallenge:true});
});
